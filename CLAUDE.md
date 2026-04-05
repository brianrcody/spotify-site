# Spotify Personal Site — CLAUDE.md

## What this project is

A single-page personal website displaying the owner's Spotify listening data. Visitors
do not authenticate — the server fetches all data using the owner's credentials. The page
has nine vertically stacked sections and no client-side routing.

Live at: `https://yourdomain.com/spotify/` (adjust to actual domain)

---

## Architecture

**Three-tier data model:**

| Tier | Data | Mechanism | Frequency |
|---|---|---|---|
| Pre-generated | Profile, top artists, top tracks, current obsession | Cron writes JSON to disk | Daily (3 AM) |
| On-demand (live) | Last played album, last played track | PHP proxies live Spotify call | Per page load |
| Polled (live) | Now Playing | JS polls PHP endpoint | Every 30 s |
| Manual | Favorites (1000+ tracks) | Run `fetch-favorites.php` by hand | On demand |

`index.html` is a static file. All data arrives via `fetch()` to PHP endpoints under
`/api/`. PHP endpoints either serve pre-generated JSON from `spotify-private/data/` or
proxy live Spotify API calls. Token management is entirely server-side.

---

## Directory structure

```
/home/YOURUSERNAME/
  public_html/spotify/          ← webroot (web-accessible)
    index.html
    css/style.css
    js/app.js
    fonts/                      ← 7 self-hosted woff2 files (not in git)
    api/
      profile.php               ← serves profile.json
      top-artists.php           ← serves top-artists.json
      top-tracks.php            ← serves top-tracks.json
      current-obsession.php     ← serves current-obsession.json
      favorites.php             ← serves favorites.json
      last-updated.php          ← serves last-updated.json
      recently-played.php       ← live Spotify call (last track + last album)
      now-playing.php           ← live Spotify call (polled every 30s)
    setup/
      step1.php                 ← one-time PKCE auth initiation
      callback.php              ← one-time PKCE auth callback

  spotify-private/              ← NOT web-accessible
    config.php                  ← client ID, refresh token, passphrase
    token-cache.json            ← cached access token, expiry, and refresh token (writable)
    lib/spotify.php             ← shared auth + API helpers
    scripts/
      cron-daily.php            ← daily cron: profile, top artists, top tracks, obsession
      fetch-favorites.php       ← manual: full Favorites playlist aggregation
    data/                       ← pre-generated JSON files (writable by PHP + cron)
```

**Critical constraint:** `spotify-private/` must remain outside `public_html/`. On
a2hosting shared hosting, anything outside `public_html/` is not web-accessible. The
API files reference it via `dirname(__DIR__, 2) . '/spotify-private/config.php'`
(resolves correctly when webroot is at `public_html/spotify/`).

---

## Key files

### `spotify-private/lib/spotify.php`
The only file that manages tokens. All other PHP files include it.

- `get_access_token()` — reads/writes `token-cache.json`; refreshes via Spotify's token
  endpoint if the cached token expires within 60 seconds. Seeds the refresh token from
  `SPOTIFY_REFRESH_TOKEN` in `config.php` on first run (when cache has no `refresh_token`),
  then persists any rotated refresh token Spotify returns so rotation is handled automatically.
- `spotify_get($path, $query)` — authenticated GET; throws on non-2xx.
- `spotify_get_nullable($path, $query)` — same but returns `null` on HTTP 204 (used by
  `now-playing.php` since Spotify returns 204 when nothing is playing).
- `spotify_get_all_pages($path, $query)` — paginates through all pages (100/page),
  100ms sleep between requests. Returns a flat array of all `items`.
- `json_response()`, `json_error()`, `serve_data_file()` — response helpers.

### `spotify-private/config.php`
Defines: `USER_HOME`, `SPOTIFY_CLIENT_ID`, `SPOTIFY_REFRESH_TOKEN`,
`SPOTIFY_REDIRECT_URI`, `SETUP_PASSPHRASE`, `PRIVATE_DIR`, `DATA_DIR`.

**Never commit a `config.php` with real credentials.** The file in the repo contains
placeholder values.

### `public_html/spotify/js/app.js`
Vanilla JS, no framework. On `DOMContentLoaded`, all seven pre-generated data fetches
run in parallel; each section renders independently on resolution. `pollNowPlaying()`
runs immediately then every 30 seconds. Chart.js (v4.4.4) loaded from CDN.

### `public_html/spotify/css/style.css`
Design concept: "The Record Shop After Hours." All colors are hardcoded hex — no CSS
custom properties. See the color reference below.

---

## Spotify API notes

- Playlist items endpoint: `GET /playlists/{id}/items` with the `item` response key
  (not `track`). The old `/tracks` path is deprecated as of February 2026.
- Recently-played endpoint (`/me/player/recently-played`) still uses `track` as the
  response key — only the playlist items endpoint was changed to `item`.
- Filter null items before processing: use `!empty($i['item'])` for playlist items,
  `!empty($i['track'])` for recently-played. Playlists may contain local files or
  removed tracks that show up as null.
- `popularity`, `available_markets`, and audio features are unavailable in Development
  Mode; don't reference them.
- HTTP 204 from `/me/player/currently-playing` means nothing is playing — don't attempt
  to JSON-decode the empty body.
- The Favorites playlist may contain 1000+ tracks requiring 10+ paginated requests.

---

## JSON data schemas

These are the exact shapes written by cron/scripts and consumed by the front-end.

**`profile.json`** — `{ "display_name": "...", "avatar_url": "..." }`

**`top-artists.json`** — `{ "artists": [{ "name", "spotify_url", "image_url" }] }`

**`top-tracks.json`** — `{ "tracks": [{ "name", "spotify_url", "artist_name", "artist_url" }] }`

**`current-obsession.json`** — `{ "album_name", "album_url", "album_art", "artist_name", "artist_url" }`

**`favorites.json`** — `{ "top_artists": [{ "name", "count", "tracks": [{ "name", "album" }] }], "by_year": [{ "year", "count" }] }`

**`last-updated.json`** — `{ "updated_at": 1712345678 }` (Unix timestamp)

**`recently-played` live response** — `{ "last_track": { name, spotify_url, artist_name, artist_url, album_name, album_url, album_art }, "last_album": { name, spotify_url, art, artist_name, artist_url } | null }`

**`now-playing` live response** — `{ "playing": false }` or `{ "playing": true, "track", "artist", "album", "spotify_url" }`

Note: `renderAlbumCard()` in `app.js` handles two slightly different field name shapes
(`album_art` vs `art`, `album_name` vs `name`, `album_url` vs `spotify_url`) because
`last_track` and `last_album` use different keys. Additionally, when both `name` and
`album_name` are present (i.e. `last_track` data), it renders a `.album-card-track`
element (linked to `spotify_url`) above the album name. The album is visually demoted
via the `.album-card-track + .album-card-album` CSS sibling rule.

---

## Color reference

| Token | Hex | Usage |
|---|---|---|
| Page background | `#111009` | Base |
| Surface/card bg | `#1C1A14` | Cards, lightbox, chart tooltip bg |
| Deep background | `#0D0C08` | Now Playing strip |
| Spotify green | `#1DB954` | Primary accent, chart bars, equalizer |
| Spotify green hot | `#24FF6F` | Chart bar hover only |
| Warm amber | `#D4913A` | Eyebrow labels, rank dots, track ranks |
| Primary text | `#EDE8DC` | Headlines, album names |
| Secondary text | `#C9BFA8` | Marquee, track names |
| Muted text | `#8C8678` | Tagline, artist names |
| Dim text | `#5A5548` | Timestamps, axis labels, "Nothing playing" |
| Section divider | `#2A2820` | Borders, HR, grid lines |
| Card border | `#3A3628` | Lightbox border, tooltip border, artist hover ring |

---

## Typography

All fonts are self-hosted woff2 under SIL OFL. `@font-face` declarations are in
`index.html` `<head>` (inline `<style>` block); the CSS file references the family
names. Font files live in `public_html/spotify/fonts/` and are not tracked in git.

| Font | Weights | Role |
|---|---|---|
| Playfair Display | 400, 700, 400i | Headlines, section titles, album names, marquee |
| IBM Plex Sans | 400, 500 | Body copy, artist names, track names |
| IBM Plex Mono | 400, 500 | Eyebrows, rank numbers, timestamps, chart axes |

---

## PHP compatibility

The server runs **PHP 8.2**. Modern syntax is fine: arrow functions, `str_contains`,
`array_key_first`, named arguments, the nullsafe operator (`?->`), and `match`
expressions are all available.

---

## Deployment & maintenance

**Initial setup (one-time):**
1. Create a Spotify app at developer.spotify.com, register the redirect URI, add owner email.
2. Download font woff2 files into `public_html/spotify/fonts/`.
3. Edit `spotify-private/config.php` with client ID, redirect URI, and passphrase.
4. Set permissions: `chmod 755 spotify-private/data/`, `chmod 666 token-cache.json`.
5. Visit `setup/step1.php?key=PASSPHRASE`, authorize, copy the refresh token into `config.php`.
6. Run `php cron-daily.php` and `php fetch-favorites.php` manually to populate data.
7. Set up cPanel cron: `0 3 * * * /usr/bin/php .../cron-daily.php >> .../cron.log 2>&1`.

**Ongoing:**
- `cron-daily.php` runs automatically at 3 AM, refreshing profile/artists/tracks/obsession.
- Re-run `fetch-favorites.php` manually after meaningfully updating the Favorites playlist.
- If the refresh token is ever revoked, re-run the setup flow via `step1.php`.
- Check `spotify-private/cron.log` if any section stops updating.

**Setup scripts** (`step1.php`, `callback.php`) are protected by `SETUP_PASSPHRASE`.
They can be left in place or deleted after initial setup.

---

## Design decisions and spec overrides

| Item | Decision |
|---|---|
| Artist lightbox | Uses `position: fixed` modal rather than in-flow div (spec called for in-flow as a workaround for constrained environments that don't apply in a normal browser) |
| Recently played | Single live call (`?limit=50`) serves both Last Played Album and Last Played Track, avoiding a redundant second call |
| Last Played Track card | Shows track name (primary, linked) above album name (secondary, demoted). `renderAlbumCard()` detects the track shape via presence of both `name` and `album_name` |
| Current Obsession label | "In heavy rotation" (spec said "Currently spinning") |
| Avatar size | 112×112px (spec said 88×88px) |
| Favorites | Manual script rather than cron — playlist is large and changes infrequently |
| Last updated footer | Added at page bottom; not in original functional spec |
