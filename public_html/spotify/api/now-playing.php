<?php
/**
 * Now Playing — live Spotify call, polled by client every 30 seconds.
 *
 * Returns {"playing": false} when nothing is active, or track details when playing.
 */

require_once dirname(__DIR__, 2) . '/spotify-private/config.php';
require_once PRIVATE_DIR . '/lib/spotify.php';

header('Content-Type: application/json');

try {
    $data = spotify_get_nullable('/me/player/currently-playing');

    // 204 (null) or not playing
    if ($data === null || empty($data['is_playing'])) {
        json_response(['playing' => false]);
    }

    $item = $data['item'] ?? null;
    if (!$item) {
        json_response(['playing' => false]);
    }

    json_response([
        'playing'     => true,
        'track'       => $item['name'] ?? '',
        'artist'      => $item['artists'][0]['name'] ?? '',
        'album'       => $item['album']['name'] ?? '',
        'spotify_url' => $item['external_urls']['spotify'] ?? '',
    ]);

} catch (Throwable $e) {
    json_error($e->getMessage());
}
