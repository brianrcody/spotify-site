# Troubleshooting: Live API Sections Not Loading

Affects: **Currently Playing**, **Last Played Track**, **Last Played Album**
(Pre-generated sections — Top Artists, Top Tracks, etc. — are unaffected; they serve from cached JSON.)

---

## Step 1 — Check the token cache

SSH to the server and inspect the cached token:

```bash
cat ~/spotify-private/token-cache.json
```

Convert `expires_at` to a human-readable date to confirm whether the token is expired:

```bash
date -d @<expires_at value>
```

If the token is expired and the file hasn't been updated recently, the refresh is failing.

---

## Step 2 — Try the refresh manually

```bash
curl -s -X POST https://accounts.spotify.com/api/token \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'grant_type=refresh_token&refresh_token=<refresh_token from token-cache.json>&client_id=<SPOTIFY_CLIENT_ID>&client_secret=<SPOTIFY_CLIENT_SECRET>'
```

Values for `client_id` and `client_secret` are in `~/spotify-private/config.php`.

### Error: `invalid_client`

The client secret is missing or wrong. Verify `SPOTIFY_CLIENT_SECRET` in `config.php` matches
the value shown in the Spotify Developer Dashboard (your app → Settings → Client secret).

### Error: `invalid_grant` — Invalid refresh token

The refresh token has been revoked. Proceed to Step 3.

### Success (returns JSON with `access_token`)

The credentials are fine but the cache is stale. Write the result manually or just delete the
cache and let PHP regenerate it:

```bash
rm ~/spotify-private/token-cache.json
```

Then reload the page — the live sections should recover immediately.

---

## Step 3 — Re-authorize (if refresh token is dead)

1. Visit `https://briancody.org/spotify/setup/step1.php?key=<SETUP_PASSPHRASE>`
2. Click the Spotify authorization link and approve access
3. Copy the refresh token displayed on the callback page
4. On the server, open `~/spotify-private/config.php` and replace `SPOTIFY_REFRESH_TOKEN` with the new value
5. Delete the stale token cache:
   ```bash
   rm ~/spotify-private/token-cache.json
   ```
6. Reload the page to confirm the live sections are working

---

## Step 4 — If step1.php returns a 500

Add temporary diagnostics to surface the PHP error. At the top of
`public_html/spotify/setup/step1.php`, after the opening `<?php` line, add:

```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

Deploy, reload, read the error, fix it, then remove those two lines before committing.

Common causes on a2hosting:
- Parse error in `config.php` from a bad edit (check syntax carefully)
- `pkce-verifier.txt` not writable — check permissions on `~/spotify-private/`

---

## Checklist for future-proofing

If the site breaks again after a long period of working, also check:

- **Spotify Developer Dashboard** — confirm the app still exists and is not suspended
- **Scopes** — if Spotify changes scope requirements, re-authorizing via step1.php picks up the current scope list automatically
- **`cron.log`** — `~/spotify-private/cron.log` will show if the daily cron is also failing
