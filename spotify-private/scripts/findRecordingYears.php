#!/usr/bin/env php
<?php
/**
 * findRecordingYears.php
 *
 * Diagnostic script. Fetches all tracks from the Favorites playlist and resolves
 * the original release year for each by querying the MusicBrainz recording endpoint,
 * including all releases for each recording. Only releases belonging to a release
 * group of type "Album" are considered; the earliest date among those is taken as
 * the original release year.
 *
 * Results are written to recordingYears.json for comparison against albumYears.json.
 * Does not modify favorites.json.
 *
 * Run from the command line:
 *   php findRecordingYears.php
 *
 * Note: queries once per unique track (not per album), so runtime will be longer
 * than findAlbumYears.php for large playlists.
 */

require_once dirname(__DIR__) . '/config.php';
require_once PRIVATE_DIR . '/lib/spotify.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const MB_USER_AGENT      = 'DJProggyB/1.0 (your-email@example.com)';
const MB_SCORE_THRESHOLD = 85;
const REMASTER_KEYWORDS  = ['remaster', 'reissue', 'anniversary'];

// ---------------------------------------------------------------------------
// MusicBrainz helpers
// ---------------------------------------------------------------------------

/**
 * Query MusicBrainz for the earliest Album-type release year for a given
 * track name and artist. Uses the recording endpoint with releases included.
 *
 * Returns the 4-digit year as an int, or null if no confident match was found.
 */
function mb_recording_year(string $track_name, string $artist_name): ?int
{
    $track_q  = str_replace('"', '', $track_name);
    $artist_q = str_replace('"', '', $artist_name);

    $url = 'https://musicbrainz.org/ws/2/recording?' . http_build_query([
        'query' => "recording:\"{$track_q}\" AND artist:\"{$artist_q}\"",
        'fmt'   => 'json',
        'limit' => 1,
        'inc'   => 'releases+release-groups',
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

    $data       = json_decode($body, true);
    $recordings = $data['recordings'] ?? [];

    if (empty($recordings)) {
        return null;
    }

    $top   = $recordings[0];
    $score = (int)($top['score'] ?? 0);

    if ($score < MB_SCORE_THRESHOLD) {
        return null;
    }

    // Collect release dates from releases that belong to an Album-type release group.
    $years = [];
    foreach ($top['releases'] ?? [] as $release) {
        $rg_type = $release['release-group']['primary-type'] ?? null;
        if ($rg_type !== 'Album') {
            continue;
        }
        $date = $release['date'] ?? null;
        if ($date && strlen($date) >= 4) {
            $years[] = (int)substr($date, 0, 4);
        }
    }

    if (empty($years)) {
        return null;
    }

    return min($years);
}

/**
 * Fallback year resolution using Spotify's release_date.
 * Returns null if the album name contains a remaster keyword.
 */
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
$offset      = 0;

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
$track_count = count($valid_items);

echo "Fetched {$track_count} valid tracks. Starting MusicBrainz lookups (~{$track_count}s)...\n";

// --- Per-track MusicBrainz resolution ---

$results   = [];
$mb_hits   = 0;
$mb_misses = 0;
$excluded  = 0;
$i         = 0;

foreach ($valid_items as $item) {
    $track       = $item['item'];
    $track_name  = $track['name'];
    $artist_name = $track['artists'][0]['name'] ?? '';
    $album_name  = $track['album']['name'];
    $spotify_date = $track['album']['release_date'] ?? '';

    $i++;
    if ($i % 50 === 0) {
        echo "  {$i}/{$track_count}...\n";
    }

    $mb_year = mb_recording_year($track_name, $artist_name);

    if ($mb_year !== null) {
        $year   = $mb_year;
        $source = 'musicbrainz';
        $mb_hits++;
    } else {
        $fallback = spotify_year_fallback($spotify_date, $album_name);
        $year     = $fallback;
        $source   = $fallback !== null ? 'spotify' : 'excluded';
        $mb_misses++;
        if ($fallback === null) {
            $excluded++;
        }
    }

    $results[] = [
        'track'        => $track_name,
        'artist'       => $artist_name,
        'album'        => $album_name,
        'spotify_date' => $spotify_date,
        'year'         => $year,
        'source'       => $source,
    ];

    sleep(1);
}

// Sort by artist, then album, then track for easy reading
usort($results, fn($a, $b) =>
    $a['artist'] <=> $b['artist'] ?: $a['album'] <=> $b['album'] ?: $a['track'] <=> $b['track']
);

// --- Write output ---

$output = json_encode($results, JSON_PRETTY_PRINT);
file_put_contents(DATA_DIR . '/recordingYears.json', $output);

echo "Done. {$mb_hits} MusicBrainz hits, {$mb_misses} misses ({$excluded} excluded).\n";
echo "Output written to " . DATA_DIR . "/recordingYears.json\n";
