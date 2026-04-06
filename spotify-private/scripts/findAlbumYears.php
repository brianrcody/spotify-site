#!/usr/bin/env php
<?php
/**
 * findAlbumYears.php
 *
 * Diagnostic script. Fetches all unique albums from the Favorites playlist,
 * resolves their original release year via MusicBrainz, and writes the results
 * to albumYears.json for inspection.
 *
 * Does not modify favorites.json.
 *
 * Run from the command line:
 *   php findAlbumYears.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once PRIVATE_DIR . '/lib/spotify.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const MB_USER_AGENT     = 'DJProggyB/1.0 (your-email@example.com)';
const MB_SCORE_THRESHOLD = 85;
const REMASTER_KEYWORDS  = ['remaster', 'reissue', 'anniversary'];

// ---------------------------------------------------------------------------
// MusicBrainz helpers (identical to fetch-favorites-mb.php)
// ---------------------------------------------------------------------------

function mb_lookup_year(string $album_name, string $artist_name): ?int
{
    $album_q  = str_replace('"', '', $album_name);
    $album_q  = trim(preg_replace('/\s*\(.*\)\s*$/', '', $album_q));
    $artist_q = str_replace('"', '', $artist_name);

    $url = 'https://musicbrainz.org/ws/2/release-group?' . http_build_query([
        'query' => "releasegroup:\"{$album_q}\" AND artist:\"{$artist_q}\"",
        'fmt'   => 'json',
        'limit' => 1,
    ]);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: ' . MB_USER_AGENT,
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);

    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200 || !$body) {
        return null;
    }

    $data   = json_decode($body, true);
    $groups = $data['release-groups'] ?? [];

    if (empty($groups)) {
        return null;
    }

    $top   = $groups[0];
    $score = (int)($top['score'] ?? 0);

    if ($score < MB_SCORE_THRESHOLD) {
        return null;
    }

    $date = $top['first-release-date'] ?? null;
    if (!$date) {
        return null;
    }

    return (int)substr($date, 0, 4);
}

function spotify_year_fallback(string $release_date, string $album_name): ?int
{
    foreach (REMASTER_KEYWORDS as $keyword) {
        if (stripos($album_name, $keyword) !== false) {
            return null;
        }
    }
    return $release_date ? (int)substr($release_date, 0, 4) : null;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

// --- Find the Favorites playlist ---

echo "Fetching playlists...\n";

$playlist_id = null;
$offset = 0;

while (true) {
    $page = spotify_get('/me/playlists', ['limit' => 50, 'offset' => $offset]);
    foreach ($page['items'] as $pl) {
        if ($pl['name'] === 'Favorites') {
            $playlist_id = $pl['id'];
            break 2;
        }
    }
    if ($page['next'] === null) {
        break;
    }
    $offset += 50;
}

if (!$playlist_id) {
    fwrite(STDERR, "ERROR: 'Favorites' playlist not found.\n");
    exit(1);
}

echo "Found Favorites playlist ({$playlist_id}).\n";

// --- Fetch all tracks ---

echo "Fetching tracks...\n";

$items       = spotify_get_all_pages('/playlists/' . $playlist_id . '/items');
$valid_items = array_filter($items, fn($item) => !empty($item['item']));

echo "Fetched " . count($valid_items) . " valid tracks.\n";

// --- Deduplicate albums ---

$albums = [];
foreach ($valid_items as $item) {
    $track    = $item['item'];
    $album    = $track['album'];
    $album_id = $album['id'];
    if (!isset($albums[$album_id])) {
        $albums[$album_id] = [
            'name'         => $album['name'],
            'artist'       => $track['artists'][0]['name'] ?? '',
            'release_date' => $album['release_date'] ?? '',
        ];
    }
}

$unique_album_count = count($albums);
echo "Unique albums: {$unique_album_count}. Starting MusicBrainz lookups (~{$unique_album_count}s)...\n";

// --- MusicBrainz resolution ---

$results   = [];
$mb_hits   = 0;
$mb_misses = 0;
$excluded  = 0;

foreach ($albums as $album_id => $album_info) {
    $mb_year = mb_lookup_year($album_info['name'], $album_info['artist']);

    if ($mb_year !== null) {
        $source = 'musicbrainz';
        $year   = $mb_year;
        $mb_hits++;
    } else {
        $fallback = spotify_year_fallback($album_info['release_date'], $album_info['name']);
        $year     = $fallback;
        $source   = $fallback !== null ? 'spotify' : 'excluded';
        $mb_misses++;
        if ($fallback === null) {
            $excluded++;
        }
    }

    $results[] = [
        'album'        => $album_info['name'],
        'artist'       => $album_info['artist'],
        'spotify_date' => $album_info['release_date'],
        'year'         => $year,
        'source'       => $source,
    ];

    sleep(1);
}

// Sort by artist then album for easy reading
usort($results, fn($a, $b) =>
    $a['artist'] <=> $b['artist'] ?: $a['album'] <=> $b['album']
);

// --- Write output ---

$output = json_encode($results, JSON_PRETTY_PRINT);
file_put_contents(DATA_DIR . '/albumYears.json', $output);

echo "Done. {$mb_hits} MusicBrainz hits, {$mb_misses} misses ({$excluded} excluded).\n";
echo "Output written to " . DATA_DIR . "/albumYears.json\n";
