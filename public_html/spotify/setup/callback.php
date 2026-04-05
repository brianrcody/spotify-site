<?php
/**
 * PKCE Setup — Callback
 *
 * Spotify redirects here after user authorization. Exchanges the auth code
 * for an access + refresh token pair. Displays the refresh token for manual
 * copy into config.php.
 */

require_once dirname(__DIR__, 2) . '/spotify-private/config.php';

// Handle error response from Spotify
if (isset($_GET['error'])) {
    http_response_code(400);
    die('Spotify authorization error: ' . htmlspecialchars($_GET['error']));
}

$code = $_GET['code'] ?? null;
if (!$code) {
    http_response_code(400);
    die('Missing authorization code.');
}

$verifier_file = PRIVATE_DIR . '/pkce-verifier.txt';
if (!file_exists($verifier_file)) {
    http_response_code(500);
    die('Code verifier not found. Run step1.php first.');
}

$verifier = file_get_contents($verifier_file);

// Exchange auth code for tokens
$ch = curl_init('https://accounts.spotify.com/api/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => SPOTIFY_REDIRECT_URI,
        'client_id'     => SPOTIFY_CLIENT_ID,
        'code_verifier' => $verifier,
    ]),
]);

$body = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Clean up verifier
@unlink($verifier_file);

if ($http_code !== 200) {
    http_response_code(500);
    die("Token exchange failed (HTTP $http_code): " . htmlspecialchars($body));
}

$data = json_decode($body, true);
$refresh_token = $data['refresh_token'] ?? null;

if (!$refresh_token) {
    http_response_code(500);
    die('No refresh token in response. Full response: ' . htmlspecialchars($body));
}

// Also cache the access token we just received
file_put_contents(PRIVATE_DIR . '/token-cache.json', json_encode([
    'access_token' => $data['access_token'],
    'expires_at'   => time() + ($data['expires_in'] ?? 3600),
]));

?>
<!DOCTYPE html>
<html>
<head><title>Spotify PKCE Setup — Complete</title></head>
<body style="font-family: monospace; padding: 40px; background: #111; color: #eee;">
    <h2 style="color: #1DB954;">Setup Complete</h2>
    <p>Copy the refresh token below into <code>spotify-private/config.php</code>
       as the value of <code>SPOTIFY_REFRESH_TOKEN</code>:</p>
    <pre style="background: #222; padding: 16px; border: 1px solid #333; word-break: break-all;
                color: #1DB954; font-size: 14px;"><?= htmlspecialchars($refresh_token) ?></pre>
    <p style="color: #D4913A; margin-top: 24px;">
        ⚠ Do this now. This token will not be shown again.
    </p>
    <p style="color: #888; font-size: 12px;">
        An access token has also been cached automatically. You can immediately
        run <code>cron-daily.php</code> and <code>fetch-favorites.php</code>.
    </p>
</body>
</html>
