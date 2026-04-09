#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * fetch-favorites-mb-official.php
 *
 * Resolves original release years by querying the MusicBrainz recording search
 * endpoint with status:"official", then scanning every release date returned for
 * every above-threshold recording to find the true minimum. This mirrors the logic
 * used in the EarliestDate.vbs MediaMonkey script.
 *
 * Why this is better than reading `first-release-date` off the top recording:
 *   - `first-release-date` is a derived/computed field in MB that is often
 *     poorly populated or wrong.
 *   - The actual `date` fields on individual release objects are far more
 *     consistently populated.
 *   - Scanning all releases of all above-threshold recordings and taking the
 *     minimum gives the true earliest known official release date for the song.
 *
 * Query strategy (mirrors EarliestDate.vbs):
 *   recording:"{title}" AND artistname:"{artist}" AND status:"official"
 *   limit=100, fmt=json
 *
 * The MB JSON search response includes a `releases` array inline on each
 * recording object. No separate lookup requests are needed.
 *
 * Date comparison:
 *   Dates are converted to YYYYMMDD integers for comparison. Missing month or
 *   day components are treated as 99 (not 00), so a vague year-only date like
 *   "1969" (→ 19699999) cannot incorrectly beat a specific date like "1969-03-01"
 *   (→ 19690301). Only the year is ultimately stored.
 *
 * Fallback:
 *   If MB returns no above-threshold recordings, or no qualifying release dates,
 *   we fall back to Spotify's album.release_date. On the fallback path only,
 *   tracks whose album name contains remaster/reissue/anniversary keywords are
 *   excluded from the histogram (their Spotify date may reflect the reissue).
 *
 * Output schema: identical to fetch-favorites.php. Drop-in replacement for
 * favorites.json.
 *
 * Usage: php fetch-favorites-mb-official.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once PRIVATE_DIR . '/lib/spotify.php';

// ---------------------------------------------------------------------------
// MusicBrainz configuration
// ---------------------------------------------------------------------------

const MB_BASE_URL        = 'https://musicbrainz.org/ws/2';
const MB_USER_AGENT      = 'DJProggyB-FavoritesScript/1.0 (https://yourdomain.com)';
const MB_SCORE_THRESHOLD = 85;    // minimum recording score to consider
const MB_SLEEP_US        = 1_100_000; // 1.1 s between requests (MB rate limit: 1/s)

// ---------------------------------------------------------------------------
// MusicBrainz helpers
// ---------------------------------------------------------------------------

/**
 * Escape a string for use inside a Lucene quoted-phrase field query.
 * Within double quotes only backslash and double-quote need escaping.
 */
function mb_escape(string $s): string
{
    return str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
}

/**
 * Convert a release date string (YYYY, YYYY-MM, or YYYY-MM-DD) to a sortable
 * YYYYMMDD integer. Missing month or day components are filled with 99 so that
 * vague dates sort later than specific ones within the same year, preventing a
 * bare year from incorrectly beating a real earlier date.
 *
 * Returns null if the string does not begin with a plausible 4-digit year.
 */
function date_to_int(string $date): ?int
{
    // Strip hyphens and extract components.
    $clean = str_replace('-', '', $date);
    $len   = strlen($clean);

    if ($len < 4 || !ctype_digit(substr($clean, 0, 4))) {
        return null;
    }

    $yyyy = (int) substr($clean, 0, 4);
    if ($yyyy < 1800 || $yyyy > 2099) {
        return null;
    }

    $mm = 99;
    $dd = 99;

    if ($len >= 6) {
        $mm = (int) substr($clean, 4, 2);
        if ($mm === 0) $mm = 99; // Discogs/MB sometimes uses 00 for unknown
    }
    if ($len >= 8) {
        $dd = (int) substr($clean, 6, 2);
        if ($dd === 0) $dd = 99;
    }

    return ($yyyy * 10_000) + ($mm * 100) + $dd;
}

/**
 * Query MusicBrainz for a track + artist, scanning all official release dates
 * across all above-threshold recordings to find the earliest.
 *
 * Returns ['year' => int, 'score' => int] on success, or null if no confident
 * result with a usable date was found.
 */
function mb_query_earliest_official(string $track, string $artist): ?array
{
    $query = sprintf(
        'recording:"%s" AND artistname:"%s" AND status:"official"',
        mb_escape($track),
        mb_escape($artist)
    );

    $url = MB_BASE_URL . '/recording?' . http_build_query([
        'query' => $query,
        'limit' => 100,
        'fmt'   => 'json',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . MB_USER_AGENT],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200 || $body === false || $body === '') {
        return null;
    }

    $data       = json_decode($body, true);
    $recordings = $data['recordings'] ?? [];

    if (empty($recordings)) {
        return null;
    }

    // Only the top result is used for the score gate. If it doesn't meet the
    // threshold, the whole query is a miss — low-scoring results are unreliable
    // regardless of what release dates they carry.
    $top_score = (int) ($recordings[0]['score'] ?? 0);
    if ($top_score < MB_SCORE_THRESHOLD) {
        return null;
    }

    // Scan releases across all above-threshold recordings to find the minimum
    // official release date. This mirrors the EarliestDate.vbs ResultsMB logic.
    $min_date_int = PHP_INT_MAX;
    $min_year     = null;

    foreach ($recordings as $recording) {
        $score = (int) ($recording['score'] ?? 0);
        if ($score < MB_SCORE_THRESHOLD) {
            break; // Results are ordered by score descending; no need to continue.
        }

        foreach ($recording['releases'] ?? [] as $release) {
            // Defense in depth: only count official releases, even though the
            // query already filters for status:"official".
            $release_status = strtolower($release['status'] ?? '');
            if ($release_status !== 'official') {
                continue;
            }

            $date_str = $release['date'] ?? '';
            if ($date_str === '') {
                continue;
            }

            $date_int = date_to_int($date_str);
            if ($date_int === null) {
                continue;
            }

            if ($date_int < $min_date_int) {
                $min_date_int = $date_int;
                $min_year     = (int) substr(str_replace('-', '', $date_str), 0, 4);
            }
        }
    }

    if ($min_year === null) {
        return null;
    }

    return [
        'year'  => $min_year,
        'score' => $top_score,
    ];
}

// ---------------------------------------------------------------------------
// Fallback helpers (Spotify path only)
// ---------------------------------------------------------------------------

/**
 * Return true if the album name suggests a remaster/reissue edition.
 * Used only on the Spotify fallback path — MB results bypass this check entirely.
 */
function is_likely_reissue(string $album_name): bool
{
    $lower = strtolower($album_name);
    return str_contains($lower, 'remaster')
        || str_contains($lower, 'reissue')
        || str_contains($lower, 'anniversary');
}

/**
 * Extract the year from a Spotify release_date string (YYYY, YYYY-MM, YYYY-MM-DD).
 */
function spotify_year(string $release_date): ?int
{
    return strlen($release_date) >= 4 ? (int) substr($release_date, 0, 4) : null;
}

// ---------------------------------------------------------------------------
// Playlist discovery
// ---------------------------------------------------------------------------

function find_playlist_id(string $name): ?string
{
    $offset = 0;
    do {
        $page = spotify_get('/me/playlists', ['limit' => 50, 'offset' => $offset]);
        foreach ($page['items'] ?? [] as $pl) {
            if (($pl['name'] ?? '') === $name) {
                return $pl['id'];
            }
        }
        $offset += 50;
    } while ($page['next'] ?? null);

    return null;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$script_start = time();
echo "fetch-favorites-mb-official.php\n";
echo str_repeat('-', 60) . "\n";

// 1. Find the Favorites playlist.
echo "Looking up 'Favorites' playlist... ";
$playlist_id = find_playlist_id('Favorites');
if ($playlist_id === null) {
    fwrite(STDERR, "ERROR: 'Favorites' playlist not found.\n");
    exit(1);
}
echo "found ($playlist_id)\n";

// 2. Fetch all tracks.
echo "Fetching all playlist tracks (this may take a minute)...\n";
$raw_items = spotify_get_all_pages('/playlists/' . $playlist_id . '/items');

// Filter null items (local files, removed tracks). Normalise to the 'item' key
// introduced in the February 2026 API rename, with fallback to 'track'.
$tracks = array_values(array_map(
    fn(array $item): array => $item['item'] ?? $item['track'],
    array_filter(
        $raw_items,
        fn($item) => !empty($item['track']) || !empty($item['item'])
    )
));

$total_tracks = count($tracks);
echo "Fetched $total_tracks valid tracks.\n\n";

// 3. Resolve release years via MB, with Spotify fallback.
echo "Querying MusicBrainz (1 request/sec, ~" . ceil($total_tracks / 60) . " min estimated)...\n";

/** @var array<string, ?array> Cache keyed by lowercased "{track}|{artist}" */
$mb_cache = [];

$stats = [
    'mb_hit'       => 0,  // confident MB result with at least one official release date
    'mb_miss'      => 0,  // below threshold or no usable date found; used Spotify fallback
    'spotify_used' => 0,  // fallback path, Spotify date accepted
    'excluded'     => 0,  // fallback path + reissue keyword → dropped from histogram
    'no_date'      => 0,  // no usable date from either source
];

/** @var list<array{track_name:string, artist_name:string, artist_id:string, album_name:string, year:?int, source:string}> */
$resolved = [];

foreach ($tracks as $i => $track) {
    $track_name   = $track['name']               ?? '';
    $artist_name  = $track['artists'][0]['name']  ?? '';
    $artist_id    = $track['artists'][0]['id']    ?? '';
    $album_name   = $track['album']['name']       ?? '';
    $spotify_date = $track['album']['release_date'] ?? '';

    if ($i > 0 && $i % 50 === 0) {
        echo sprintf(
            "  ... %d / %d tracks processed (%ds elapsed)\n",
            $i, $total_tracks, time() - $script_start
        );
    }

    $cache_key = strtolower($track_name) . '|' . strtolower($artist_name);

    if (!array_key_exists($cache_key, $mb_cache)) {
        $mb_cache[$cache_key] = mb_query_earliest_official($track_name, $artist_name);
        usleep(MB_SLEEP_US);
    }

    $mb_result = $mb_cache[$cache_key];

    if ($mb_result !== null) {
        $stats['mb_hit']++;
        $resolved[] = [
            'track_name'  => $track_name,
            'artist_name' => $artist_name,
            'artist_id'   => $artist_id,
            'album_name'  => $album_name,
            'year'        => $mb_result['year'],
            'source'      => 'musicbrainz',
        ];
    } else {
        $stats['mb_miss']++;

        if (is_likely_reissue($album_name)) {
            $stats['excluded']++;
            $resolved[] = [
                'track_name'  => $track_name,
                'artist_name' => $artist_name,
                'artist_id'   => $artist_id,
                'album_name'  => $album_name,
                'year'        => null,
                'source'      => 'excluded',
            ];
        } else {
            $year = spotify_year($spotify_date);
            if ($year !== null) {
                $stats['spotify_used']++;
                $resolved[] = [
                    'track_name'  => $track_name,
                    'artist_name' => $artist_name,
                    'artist_id'   => $artist_id,
                    'album_name'  => $album_name,
                    'year'        => $year,
                    'source'      => 'spotify_fallback',
                ];
            } else {
                $stats['no_date']++;
                $resolved[] = [
                    'track_name'  => $track_name,
                    'artist_name' => $artist_name,
                    'artist_id'   => $artist_id,
                    'album_name'  => $album_name,
                    'year'        => null,
                    'source'      => 'no_date',
                ];
            }
        }
    }
}

echo sprintf("Done. %ds total.\n\n", time() - $script_start);

// ---------------------------------------------------------------------------
// 4. Aggregate: top 50 artists by track count.
// ---------------------------------------------------------------------------

$artist_map = [];

foreach ($resolved as $r) {
    $id = $r['artist_id'];
    if (!isset($artist_map[$id])) {
        $artist_map[$id] = ['name' => $r['artist_name'], 'count' => 0, 'tracks' => []];
    }
    $artist_map[$id]['count']++;
    $artist_map[$id]['tracks'][] = ['name' => $r['track_name'], 'album' => $r['album_name']];
}

foreach ($artist_map as &$artist) {
    usort($artist['tracks'], fn($a, $b) =>
        [$a['album'], $a['name']] <=> [$b['album'], $b['name']]
    );
}
unset($artist);

usort($artist_map, fn($a, $b) => $b['count'] <=> $a['count']);
$top_artists = array_slice(array_values($artist_map), 0, 50);

// ---------------------------------------------------------------------------
// 5. Aggregate: release year histogram.
// ---------------------------------------------------------------------------

$year_counts = [];
$year_tracks = [];
foreach ($resolved as $r) {
    if ($r['year'] === null) {
        continue;
    }
    $year_counts[$r['year']] = ($year_counts[$r['year']] ?? 0) + 1;
    $year_tracks[$r['year']][] = ['name' => $r['track_name'], 'artist' => $r['artist_name']];
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

// ---------------------------------------------------------------------------
// 6. Write output (atomic rename).
// ---------------------------------------------------------------------------

$output    = ['top_artists' => $top_artists, 'by_year' => $by_year];
$tmp_path  = DATA_DIR . '/favorites.tmp.json';
$dest_path = DATA_DIR . '/favorites.json';

file_put_contents($tmp_path, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
rename($tmp_path, $dest_path);

// ---------------------------------------------------------------------------
// 7. Summary.
// ---------------------------------------------------------------------------

$years     = array_column($by_year, 'year');
$year_span = empty($years) ? 'n/a' : (min($years) . '–' . max($years));

echo str_repeat('-', 60) . "\n";
echo "Summary\n";
echo str_repeat('-', 60) . "\n";
echo sprintf("  Tracks processed               : %d\n", $total_tracks);
echo sprintf("  Unique MB queries issued       : %d\n", count($mb_cache));
echo sprintf("  MB hit (official date found)   : %d\n", $stats['mb_hit']);
echo sprintf("  MB miss → Spotify date used    : %d\n", $stats['spotify_used']);
echo sprintf("  MB miss → excluded (reissue)   : %d\n", $stats['excluded']);
echo sprintf("  No usable date                 : %d\n", $stats['no_date']);
echo sprintf("  Artists in top_artists         : %d\n", count($top_artists));
echo sprintf("  Years spanned                  : %s\n", $year_span);
echo sprintf("  Total runtime                  : %ds\n", time() - $script_start);
echo "\nWrote: $dest_path\n";
