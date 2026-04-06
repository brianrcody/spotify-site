#!/usr/bin/env php
<?php
/**
 * fetch-favorites-mb.php
 *
 * Fetches and aggregates the Favorites playlist, using MusicBrainz release-group
 * lookups to resolve original release years rather than relying on Spotify's
 * release_date (which reflects reissue dates for remasters).
 *
 * Runtime: approximately 1 second per unique album due to MusicBrainz rate limiting.
 * Expect ~5 minutes for a playlist with ~300 unique albums.
 *
 * Run from the command line:
 *   php fetch-favorites-mb.php
 *
 * Output: spotify-private/data/favorites.json (same schema as fetch-favorites.php)
 */

require_once dirname(__DIR__) . '/config.php';
require_once PRIVATE_DIR . '/lib/spotify.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

/**
 * MusicBrainz requires a descriptive User-Agent identifying your application
 * and a contact point. Requests without this header may be rejected.
 * Format: AppName/Version (contact-url-or-email)
 */
const MB_USER_AGENT = 'DJProggyB/1.0 (your-email@example.com)';

/**
 * MusicBrainz search score threshold (0–100). Results below this are treated
 * as no-match and fall back to the Spotify date + substring filter.
 */
const MB_SCORE_THRESHOLD = 85;

/**
 * Fallback: album name substrings (case-insensitive) that indicate a remaster
 * or reissue. Used only when MusicBrainz lookup fails or returns a low score.
 * These albums are excluded from the histogram entirely.
 */
const REMASTER_KEYWORDS = ['remaster', 'reissue', 'anniversary'];

// ---------------------------------------------------------------------------
// MusicBrainz helpers
// ---------------------------------------------------------------------------

/**
 * Query MusicBrainz for the original release year of an album, using the
 * release-group endpoint (which carries a first-release-date across all editions).
 *
 * Returns the 4-digit year as an int, or null if no confident match was found.
 */
function mb_lookup_year(string $album_name, string $artist_name): ?int
{
    // Strip double-quotes from names before embedding them in the Lucene query,
    // otherwise they break the quoted phrase syntax.
    // Also strip any parenthetical suffix (e.g. "(Steven Wilson Mix)", "(Deluxe Edition)")
    // so that edition qualifiers don't confuse the release-group search.
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

/**
 * Fallback year resolution using Spotify's release_date.
 * Returns null (excluded from histogram) if the album name contains a remaster
 * keyword, otherwise returns the year parsed from the date string.
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

$items = spotify_get_all_pages('/playlists/' . $playlist_id . '/items');

// Filter null items (local files, removed tracks)
$valid_items = array_filter($items, fn($item) => !empty($item['item']));
$track_count = count($valid_items);

echo "Fetched {$track_count} valid tracks.\n";

// --- Deduplicate albums ---

// Map of spotify_album_id => [name, artist, release_date] for MusicBrainz queries.
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

// Map of spotify_album_id => int year (or null = excluded from histogram).
$year_map  = [];
$mb_hits   = 0;
$mb_misses = 0;
$excluded  = 0;

foreach ($albums as $album_id => $album_info) {
    $year = mb_lookup_year($album_info['name'], $album_info['artist']);

    if ($year !== null) {
        $year_map[$album_id] = $year;
        $mb_hits++;
    } else {
        // MusicBrainz didn't return a confident match — fall back to Spotify date
        // with the remaster keyword filter applied.
        $fallback = spotify_year_fallback($album_info['release_date'], $album_info['name']);
        $year_map[$album_id] = $fallback; // may be null (excluded)
        $mb_misses++;
        if ($fallback === null) {
            $excluded++;
        }
    }

    sleep(1); // MusicBrainz rate limit: 1 req/sec
}

echo "MusicBrainz: {$mb_hits} hits, {$mb_misses} misses "
   . "({$excluded} excluded by remaster filter on fallback).\n";

// --- Artist aggregation (unchanged from fetch-favorites.php) ---

$artist_map = [];
foreach ($valid_items as $item) {
    $track      = $item['item'];
    $artist_id  = $track['artists'][0]['id']   ?? null;
    $artist_name = $track['artists'][0]['name'] ?? 'Unknown';
    if (!$artist_id) {
        continue;
    }

    if (!isset($artist_map[$artist_id])) {
        $artist_map[$artist_id] = ['name' => $artist_name, 'count' => 0, 'tracks' => []];
    }
    $artist_map[$artist_id]['count']++;
    $artist_map[$artist_id]['tracks'][] = [
        'name'  => $track['name'],
        'album' => $track['album']['name'],
    ];
}

usort($artist_map, fn($a, $b) => $b['count'] <=> $a['count']);
$top_artists = array_slice(array_values($artist_map), 0, 50);

foreach ($top_artists as &$artist) {
    usort($artist['tracks'], fn($a, $b) =>
        $a['album'] <=> $b['album'] ?: $a['name'] <=> $b['name']
    );
}
unset($artist);

// --- Year histogram ---

$year_buckets = [];
foreach ($valid_items as $item) {
    $album_id = $item['item']['album']['id'];
    $year     = $year_map[$album_id] ?? null;
    if ($year === null) {
        continue;
    }
    $year_buckets[$year] = ($year_buckets[$year] ?? 0) + 1;
}

ksort($year_buckets);
$by_year = array_map(
    fn($year, $count) => ['year' => (int)$year, 'count' => $count],
    array_keys($year_buckets),
    $year_buckets
);

// --- Atomic write ---

$output = json_encode([
    'top_artists' => $top_artists,
    'by_year'     => $by_year,
], JSON_PRETTY_PRINT);

$tmp  = DATA_DIR . '/favorites.tmp.json';
$dest = DATA_DIR . '/favorites.json';
file_put_contents($tmp, $output);
rename($tmp, $dest);

// --- Summary ---

$years      = array_column($by_year, 'year');
$year_range = empty($years) ? 'n/a' : min($years) . '–' . max($years);

echo "Done. {$track_count} tracks | " . count($top_artists) . " artists | years {$year_range}.\n";
