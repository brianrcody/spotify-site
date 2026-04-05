#!/usr/bin/env php
<?php
/**
 * Daily cron job — fetches and caches:
 *   - Profile (display name, avatar)
 *   - Top 5 artists (short_term)
 *   - Top 10 tracks (short_term)
 *   - Current Album Obsession (first track of named playlist)
 *
 * Each section is independent; a failure in one does not block the others.
 */

require_once dirname(__DIR__) . '/config.php';
require_once PRIVATE_DIR . '/lib/spotify.php';

$errors = 0;

// --- Profile ---
try {
    $me = spotify_get('/me');
    $profile = [
        'display_name' => $me['display_name'] ?? '',
        'avatar_url'   => $me['images'][0]['url'] ?? null,
    ];
    file_put_contents(DATA_DIR . '/profile.json', json_encode($profile));
    fwrite(STDOUT, "Profile: OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Profile FAILED: {$e->getMessage()}\n");
    $errors++;
}

// --- Top Artists ---
try {
    $response = spotify_get('/me/top/artists', [
        'time_range' => 'short_term',
        'limit'      => 5,
    ]);
    $artists = array_map(fn($a) => [
        'name'        => $a['name'],
        'spotify_url' => $a['external_urls']['spotify'] ?? '',
        'image_url'   => $a['images'][0]['url'] ?? null,
    ], $response['items'] ?? []);
    file_put_contents(DATA_DIR . '/top-artists.json', json_encode(['artists' => $artists]));
    fwrite(STDOUT, "Top Artists: OK (" . count($artists) . ")\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Top Artists FAILED: {$e->getMessage()}\n");
    $errors++;
}

// --- Top Tracks ---
try {
    $response = spotify_get('/me/top/tracks', [
        'time_range' => 'short_term',
        'limit'      => 10,
    ]);
    $tracks = array_map(fn($t) => [
        'name'        => $t['name'],
        'spotify_url' => $t['external_urls']['spotify'] ?? '',
        'artist_name' => $t['artists'][0]['name'] ?? '',
        'artist_url'  => $t['artists'][0]['external_urls']['spotify'] ?? '',
    ], $response['items'] ?? []);
    file_put_contents(DATA_DIR . '/top-tracks.json', json_encode(['tracks' => $tracks]));
    fwrite(STDOUT, "Top Tracks: OK (" . count($tracks) . ")\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Top Tracks FAILED: {$e->getMessage()}\n");
    $errors++;
}

// --- Current Album Obsession ---
try {
    $playlist_id = null;
    $offset = 0;

    while (true) {
        $page = spotify_get('/me/playlists', ['limit' => 50, 'offset' => $offset]);
        foreach ($page['items'] ?? [] as $pl) {
            if (($pl['name'] ?? '') === 'Current Album Obsession') {
                $playlist_id = $pl['id'];
                break 2;
            }
        }
        if (empty($page['next'])) {
            break;
        }
        $offset += 50;
        usleep(100_000);
    }

    if (!$playlist_id) {
        fwrite(STDERR, "Current Obsession: playlist not found (skipping)\n");
    } else {
        $items = spotify_get("/playlists/$playlist_id/items", ['limit' => 1]);
        $first = $items['items'][0]['item'] ?? null;

        if (!$first) {
            fwrite(STDERR, "Current Obsession: playlist empty or first item null (skipping)\n");
        } else {
            $obsession = [
                'album_name'  => $first['album']['name'] ?? '',
                'album_url'   => $first['album']['external_urls']['spotify'] ?? '',
                'album_art'   => $first['album']['images'][0]['url'] ?? '',
                'artist_name' => $first['album']['artists'][0]['name'] ?? '',
                'artist_url'  => $first['album']['artists'][0]['external_urls']['spotify'] ?? '',
            ];
            file_put_contents(DATA_DIR . '/current-obsession.json', json_encode($obsession));
            fwrite(STDOUT, "Current Obsession: OK ({$obsession['album_name']})\n");
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Current Obsession FAILED: {$e->getMessage()}\n");
    $errors++;
}

// --- Last Updated ---
file_put_contents(DATA_DIR . '/last-updated.json', json_encode(['updated_at' => time()]));
fwrite(STDOUT, "Last Updated: written\n");

if ($errors > 0) {
    fwrite(STDERR, "\nCompleted with $errors error(s).\n");
    exit(1);
}

fwrite(STDOUT, "\nAll sections completed successfully.\n");
