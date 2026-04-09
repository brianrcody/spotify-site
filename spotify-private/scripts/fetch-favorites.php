#!/usr/bin/env php
<?php
/**
 * Fetch Favorites — run manually from the command line.
 *
 * Fetches all tracks from the "Favorites" playlist, aggregates:
 *   - Top 50 artists by track count (with track listings)
 *   - Release year distribution (excluding remasters/reissues)
 *
 * Usage: php fetch-favorites.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once PRIVATE_DIR . '/lib/spotify.php';

// --- Find the Favorites playlist ---
$playlist_id = null;
$offset = 0;

while (true) {
    $page = spotify_get('/me/playlists', ['limit' => 50, 'offset' => $offset]);
    foreach ($page['items'] ?? [] as $pl) {
        if (($pl['name'] ?? '') === 'Favorites') {
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
    fwrite(STDERR, "Error: Playlist named 'Favorites' not found.\n");
    exit(1);
}

fwrite(STDOUT, "Found Favorites playlist: $playlist_id\n");

// --- Fetch all tracks ---
$raw_items = spotify_get_all_pages("/playlists/$playlist_id/items");
fwrite(STDOUT, "Fetched " . count($raw_items) . " raw items.\n");

// Filter nulls
$items = array_filter($raw_items, fn($i) => !empty($i['item']));
fwrite(STDOUT, "Valid items after filtering: " . count($items) . "\n");

// --- Aggregate by artist ---
// Key: artist ID, Value: ['name' => ..., 'tracks' => [...]]
$artist_map = [];

foreach ($items as $entry) {
    $track = $entry['item'];
    $artist_id   = $track['artists'][0]['id'] ?? 'unknown';
    $artist_name = $track['artists'][0]['name'] ?? 'Unknown';
    $track_name  = $track['name'] ?? '';
    $album_name  = $track['album']['name'] ?? '';

    if (!isset($artist_map[$artist_id])) {
        $artist_map[$artist_id] = [
            'name'   => $artist_name,
            'tracks' => [],
        ];
    }

    $artist_map[$artist_id]['tracks'][] = [
        'name'  => $track_name,
        'album' => $album_name,
    ];
}

// Sort by track count descending, take top 50
uasort($artist_map, fn($a, $b) => count($b['tracks']) - count($a['tracks']));
$top_artists_raw = array_slice($artist_map, 0, 50, true);

// Sort each artist's tracks by album then track name
$top_artists = [];
foreach ($top_artists_raw as $data) {
    $tracks = $data['tracks'];
    usort($tracks, function ($a, $b) {
        $cmp = strcasecmp($a['album'], $b['album']);
        return $cmp !== 0 ? $cmp : strcasecmp($a['name'], $b['name']);
    });

    $top_artists[] = [
        'name'   => $data['name'],
        'count'  => count($tracks),
        'tracks' => $tracks,
    ];
}

fwrite(STDOUT, "Top artist: {$top_artists[0]['name']} ({$top_artists[0]['count']} tracks)\n");

// --- Aggregate by year ---
$remaster_keywords = ['remaster', 'reissue', 'anniversary'];
$year_counts = [];
$year_tracks = [];

foreach ($items as $entry) {
    $track = $entry['item'];
    $album_name  = $track['album']['name'] ?? '';
    $track_name  = $track['name'] ?? '';
    $artist_name = $track['artists'][0]['name'] ?? '';

    // Skip remasters/reissues
    $lower = strtolower($album_name);
    $skip = false;
    foreach ($remaster_keywords as $kw) {
        if (str_contains($lower, $kw)) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    $release_date = $track['album']['release_date'] ?? '';
    $year = (int) substr($release_date, 0, 4);
    if ($year < 1900 || $year > 2100) {
        continue;
    }

    $year_counts[$year] = ($year_counts[$year] ?? 0) + 1;
    $year_tracks[$year][] = ['name' => $track_name, 'artist' => $artist_name];
}

ksort($year_counts);

foreach ($year_tracks as &$yt) {
    usort($yt, fn($a, $b) => [$a['artist'], $a['name']] <=> [$b['artist'], $b['name']]);
}
unset($yt);

$by_year = array_map(
    fn($year, $count) => ['year' => $year, 'count' => $count, 'tracks' => $year_tracks[$year]],
    array_keys($year_counts),
    array_values($year_counts)
);

$year_span = empty($year_counts)
    ? 'none'
    : array_key_first($year_counts) . '–' . array_key_last($year_counts);
fwrite(STDOUT, "Years spanned: $year_span (" . count($year_counts) . " distinct years)\n");

// --- Write atomically ---
$output = [
    'top_artists' => $top_artists,
    'by_year'     => $by_year,
];

$tmp_path  = DATA_DIR . '/favorites.tmp.json';
$dest_path = DATA_DIR . '/favorites.json';

file_put_contents($tmp_path, json_encode($output));
rename($tmp_path, $dest_path);

fwrite(STDOUT, "\nWrote favorites.json successfully.\n");
fwrite(STDOUT, "  Tracks processed: " . count($items) . "\n");
fwrite(STDOUT, "  Artists (top 50): " . count($top_artists) . "\n");
fwrite(STDOUT, "  Year buckets:     " . count($by_year) . "\n");
