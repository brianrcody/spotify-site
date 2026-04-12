---
name: add-new-song
description: Add a new track to favorites.json (by_year and optionally top_artists) and append a year-only override entry to overrides.json. Args: track name, artist, album, year — all optional at invocation, will be prompted if missing.
---

You are adding a new track to `favorites.json` in the current working directory.

## Setup

Read `favorites.json` and `overrides.json` (the latter may not exist yet).

## Step 1 — Gather track details

**If arguments were passed at invocation**, parse them in order: track, artist, album, year.
Any missing fields must be prompted interactively.

**If no arguments were passed**, ask for all four fields at once:

> Track name, artist, album, and release year?

Wait for the user's response before proceeding.

## Step 2 — Determine top_artists impact

Search `top_artists` for an entry whose `name` matches the artist (case-insensitive).

- **Artist found:** the track will be added to their existing entry (count + 1, track appended in album-alphabetical order among their tracks).
- **Artist not found:** the track affects `by_year` only. No changes to `top_artists`.

Briefly state which case applies before making any edits.

## Step 3 — Update top_artists (if applicable)

Find the artist's entry. Insert the new track object `{ "name": "...", "album": "..." }` into their `tracks` array, maintaining alphabetical order by album name. If two tracks share the same album, sort by track name within the album. Increment their `count` by 1.

## Step 4 — Update by_year

Find the year bucket in `by_year` whose `year` matches the given year.

- **Year bucket exists:** insert the new track object `{ "name": "...", "artist": "..." }` into `tracks`, maintaining alphabetical order by artist name (then by track name within the same artist). Increment `count` by 1.
- **Year bucket does not exist:** create a new bucket `{ "year": YYYY, "count": 1, "tracks": [...] }` and insert it in sorted year order within the `by_year` array.

## Step 5 — Write favorites.json

Apply both edits to `favorites.json`. Use the Edit tool to make targeted, minimal changes — do not rewrite the whole file.

## Step 6 — Append to overrides.json

Append a `year`-only entry (the format for slotting a previously missing track):

```json
{ "track": "Track Name", "artist": "Artist", "year": YYYY }
```

Keep `overrides.json` as a valid JSON array. If the file does not exist, create it as a single-element array.

## Step 7 — Confirm

Report a one-line summary:

```
Added "Track Name" by Artist (YYYY)[, updated top_artists count to N].
```
