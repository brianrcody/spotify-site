# spotify-site

A single-page personal website that displays your Spotify listening data. Visitors don't authenticate — the server fetches everything using your credentials via the Spotify Web API.

## What it shows

- **Profile** — display name and avatar
- **Top Artists** — your all-time top artists with links
- **Top Tracks** — your all-time top tracks
- **Current Obsession** — the album you're currently fixated on (manually curated)
- **Favorites** — stats from your Favorites playlist: top artists by track count, listening by year
- **Last Played** — most recently played track and album (live, per page load)
- **Now Playing** — currently playing track (polled every 30 seconds)

## Architecture

Three data tiers:

| Tier | Data | Mechanism | Frequency |
|---|---|---|---|
| Pre-generated | Profile, top artists, top tracks, current obsession | Cron writes JSON to disk | Daily (3 AM) |
| On-demand | Last played track and album | PHP proxies live Spotify call | Per page load |
| Polled | Now Playing | JS polls PHP endpoint | Every 30 s |
| Manual | Favorites (1000+ tracks) | Run a favorites script by hand (see scripts below) | On demand |

`index.html` is a static file. All data arrives via `fetch()` calls to PHP endpoints under `/api/`. Token management is entirely server-side.

## Stack

- Vanilla JS (no framework), Chart.js for the favorites chart
- PHP 8.2 for API endpoints and token management
- Designed for shared hosting (tested on a2hosting)

## Directory structure

```
public_html/spotify/        ← webroot (web-accessible)
  index.html
  css/style.css
  js/app.js
  fonts/                    ← self-hosted woff2 files (not in git, see Fonts)
  api/                      ← PHP endpoints
  setup/                    ← one-time PKCE auth flow

spotify-private/            ← NOT web-accessible
  config.php                ← credentials and paths (fill in before deploying)
  token-cache.json          ← auto-managed access token cache (must be writable)
  lib/spotify.php           ← shared auth + API helpers
  scripts/
    cron-daily.php          ← daily cron job
    fetch-favorites.php     ← manual favorites aggregation (uses Spotify release_date)
    fetch-favorites-mb.php  ← manual favorites aggregation using MusicBrainz for original release years
    findAlbumYears.php      ← diagnostic: inspect year resolution before a full MB run
  data/                     ← pre-generated JSON (must be writable, not in git)
```

`spotify-private/` must live **outside** `public_html/`. On shared hosting, anything outside `public_html/` is not web-accessible.

## Setup

1. Create a Spotify app at [developer.spotify.com](https://developer.spotify.com/dashboard), register your redirect URI, and add your Spotify account as a user.
2. Download the required font files into `public_html/spotify/fonts/` (see Fonts below).
3. Edit `spotify-private/config.php` with your client ID, redirect URI, and a setup passphrase.
4. Set permissions: `chmod 755 spotify-private/data/` and `chmod 666 spotify-private/token-cache.json`.
5. Visit `setup/step1.php?key=YOUR_PASSPHRASE`, authorize the app, and copy the refresh token into `config.php`.
6. Run `php cron-daily.php` and `php fetch-favorites.php` manually to generate the initial data files.
7. Add a cron job: `0 3 * * * /usr/bin/php /path/to/spotify-private/scripts/cron-daily.php >> /path/to/spotify-private/cron.log 2>&1`

## Fonts

The site uses three self-hosted fonts (SIL OFL licensed). Download the woff2 files and place them in `public_html/spotify/fonts/`:

- **Playfair Display** — Regular (400), Bold (700), Italic (400i)
- **IBM Plex Sans** — Regular (400), Medium (500)
- **IBM Plex Mono** — Regular (400), Medium (500)

## License

MIT — see [LICENSE](LICENSE).
