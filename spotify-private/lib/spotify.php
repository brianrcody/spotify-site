<?php
/**
 * Spotify API helper library.
 *
 * Handles token management (refresh + cache) and provides convenience
 * wrappers for GET requests and paginated fetches.
 */

/**
 * Obtain a valid access token, refreshing from Spotify if the cached one is expired.
 *
 * Reads/writes PRIVATE_DIR/token-cache.json. The cached token is considered valid
 * if it expires more than 60 seconds in the future.
 *
 * @return string A valid Spotify access token.
 * @throws RuntimeException on token refresh failure.
 */
function get_access_token(): string
{
    $cache_file = PRIVATE_DIR . '/token-cache.json';

    $cached = file_exists($cache_file)
        ? (json_decode(file_get_contents($cache_file), true) ?? [])
        : [];

    if (isset($cached['access_token'], $cached['expires_at'])
        && $cached['expires_at'] > time() + 60) {
        return $cached['access_token'];
    }

    // Use the cached refresh token if available; fall back to the seed in config.php.
    $refresh_token = $cached['refresh_token'] ?? SPOTIFY_REFRESH_TOKEN;

    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
            'client_id'     => SPOTIFY_CLIENT_ID,
        ]),
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        throw new RuntimeException("Token refresh failed (HTTP $code): $body");
    }

    $data = json_decode($body, true);

    // Spotify may rotate the refresh token on each use (PKCE). Persist the new
    // one if provided so the old token isn't used on the next refresh cycle.
    file_put_contents($cache_file, json_encode([
        'access_token'  => $data['access_token'],
        'expires_at'    => time() + ($data['expires_in'] ?? 3600),
        'refresh_token' => $data['refresh_token'] ?? $refresh_token,
    ]));

    return $data['access_token'];
}

/**
 * Make an authenticated GET request to the Spotify Web API.
 *
 * @param string $path  API path starting with '/' (e.g. '/me/top/artists').
 * @param array  $query Optional query parameters.
 * @return array Decoded JSON response.
 * @throws RuntimeException on non-2xx responses.
 */
function spotify_get(string $path, array $query = []): array
{
    $token = get_access_token();
    $url   = 'https://api.spotify.com/v1' . $path;

    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Spotify API error (HTTP $code) on $path: $body");
    }

    return json_decode($body, true) ?? [];
}

/**
 * Make an authenticated GET request that may return HTTP 204 (no content).
 *
 * @param string $path  API path.
 * @param array  $query Optional query parameters.
 * @return array|null Decoded JSON, or null on 204.
 * @throws RuntimeException on non-2xx/non-204 responses.
 */
function spotify_get_nullable(string $path, array $query = []): ?array
{
    $token = get_access_token();
    $url   = 'https://api.spotify.com/v1' . $path;

    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 204) {
        return null;
    }

    if ($code < 200 || $code >= 300) {
        throw new RuntimeException("Spotify API error (HTTP $code) on $path: $body");
    }

    return json_decode($body, true) ?? [];
}

/**
 * Fetch all pages of a paginated Spotify endpoint.
 *
 * @param string $path          API path (e.g. '/playlists/{id}/items').
 * @param array  $initial_query Base query parameters; 'limit' defaults to 100.
 * @return array Flat array of all items across all pages.
 */
function spotify_get_all_pages(string $path, array $initial_query = []): array
{
    $query   = array_merge(['limit' => 100], $initial_query);
    $results = [];

    while (true) {
        $response = spotify_get($path, $query);
        $items    = $response['items'] ?? [];
        array_push($results, ...$items);

        if (empty($response['next'])) {
            break;
        }

        // Extract offset from the next URL
        parse_str(parse_url($response['next'], PHP_URL_QUERY) ?? '', $next_params);
        $query['offset'] = (int) ($next_params['offset'] ?? 0);

        usleep(100_000); // 0.1s between pages
    }

    return $results;
}

/**
 * Send a JSON response and exit.
 */
function json_response(array $data): never
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Send a JSON error response and exit.
 */
function json_error(string $message, int $status = 500): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

/**
 * Serve a pre-generated JSON data file. Returns 503 if the file doesn't exist yet.
 */
function serve_data_file(string $filename): never
{
    $path = DATA_DIR . '/' . $filename;

    if (!file_exists($path)) {
        json_error('Data not yet available', 503);
    }

    header('Content-Type: application/json');
    readfile($path);
    exit;
}
