<?php
/**
 * Recently Played — live Spotify call.
 *
 * Returns last played track and last played album (heuristic: first run of 3+
 * consecutive tracks from the same album in the 50 most recent plays).
 */

require_once dirname(__DIR__, 2) . '/spotify-private/config.php';
require_once PRIVATE_DIR . '/lib/spotify.php';

header('Content-Type: application/json');

try {
    $response = spotify_get('/me/player/recently-played', ['limit' => 50]);
    $items = $response['items'] ?? [];

    // Filter out null/empty items (local files, removed tracks).
    // Note: /me/player/recently-played uses the 'track' key. Only the playlist
    // items endpoint (/playlists/{id}/items) uses 'item'.
    $items = array_values(array_filter($items, fn($i) => !empty($i['track'])));

    if (empty($items)) {
        json_response(['last_track' => null, 'last_album' => null]);
    }

    // Last played track — first item in the list
    $first = $items[0]['track'];
    $last_track = [
        'name'        => $first['name'],
        'spotify_url' => $first['external_urls']['spotify'] ?? '',
        'artist_name' => $first['artists'][0]['name'] ?? '',
        'artist_url'  => $first['artists'][0]['external_urls']['spotify'] ?? '',
        'album_name'  => $first['album']['name'] ?? '',
        'album_url'   => $first['album']['external_urls']['spotify'] ?? '',
        'album_art'   => $first['album']['images'][0]['url'] ?? '',
    ];

    // Last played album heuristic — first run of 3+ consecutive same-album tracks
    $last_album = null;
    $run_length = 1;
    $run_album_id = $items[0]['track']['album']['id'] ?? null;

    for ($i = 1, $count = count($items); $i < $count; $i++) {
        $current_album_id = $items[$i]['track']['album']['id'] ?? null;

        if ($current_album_id === $run_album_id && $current_album_id !== null) {
            $run_length++;
            if ($run_length >= 3) {
                $album = $items[$i]['track']['album'];
                $last_album = [
                    'name'        => $album['name'],
                    'spotify_url' => $album['external_urls']['spotify'] ?? '',
                    'art'         => $album['images'][0]['url'] ?? '',
                    'artist_name' => $album['artists'][0]['name'] ?? '',
                    'artist_url'  => $album['artists'][0]['external_urls']['spotify'] ?? '',
                ];
                break;
            }
        } else {
            $run_album_id = $current_album_id;
            $run_length = 1;
        }
    }

    json_response([
        'last_track' => $last_track,
        'last_album' => $last_album,
    ]);

} catch (Throwable $e) {
    json_error($e->getMessage());
}
