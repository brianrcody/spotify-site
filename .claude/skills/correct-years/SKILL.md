---
name: correct-years
description: Interactive session for correcting wrong release years in favorites.json. Walks through each track in a chosen year, prompts for corrections, updates favorites.json, and appends to overrides.json. Optional args at invocation: year and artist filter (e.g. /correct-years 2000 Pink Floyd).
---

You are running an interactive year-correction session for `favorites.json` in the current working directory.

## Setup

Read `favorites.json` and parse the `by_year` array. Also read `overrides.json` if it exists (it may not yet).

## Step 1 — Determine year (and optional artist filter)

**If arguments were passed at invocation** (e.g. `/correct-years 2000` or `/correct-years 2000 Pink Floyd`), parse them: the first token is the year, any remaining tokens are the artist filter. Skip the interactive prompt below and proceed directly to Step 2 with those values. Validate that the year exists in `by_year`; if not, say so and ask the user to choose a valid year.

**If no arguments were passed**, list the available years from `by_year` (just the year numbers and track counts, in a compact format), then ask:

> Which year would you like to review?

Wait for the user's response. The user may provide just a year (`2000`) or a year followed by an artist name (`2000 The Beatles`). If the year doesn't exist in `by_year`, say so and ask again.

If an artist name is provided, filter the track list for that year to only tracks whose artist matches (case-insensitive substring match). If no tracks match the artist in that year, say so and ask again.

## Step 2 — Iterate through tracks

Show a header: `Year YYYY — N tracks` (or `Year YYYY — N tracks by {Artist}` if filtered)

For each track in that year's `tracks` array, show:

```
[i/N] "Track Name" — Artist
Update year? (y / s[kip] / q[uit]):
```

- **s** or **skip** — leave this track unchanged, move to the next
- **q** or **quit** — end the session immediately (before finishing the year)
- **y** — proceed to Step 3 for this track

## Step 3 — Suggest a year

Based on your knowledge of the artist and track, state your best guess at the correct release year and your confidence. Format:

```
Suggested year: YYYY (brief reason, e.g. "original album released YYYY")
Accept, enter a different year, or s[kip]/q[uit]:
```

The user may:
- Press enter or type the suggested year → accept it
- Type a different year → use that year
- Type `a` or `accept` → accept the suggested year
- Type `s` or `skip` → leave unchanged, continue
- Type `q` or `quit` → end session

## Step 4 — Apply the correction

Given `from_year` (the current bucket) and `to_year` (the destination):

1. **Read** `favorites.json` fresh (to avoid stale state after prior edits in this session — skip the re-read if this is the first correction and no other edits have been made).
2. Remove the track object from `by_year[from_year].tracks`. Decrement `by_year[from_year].count`. If count reaches 0, remove that year bucket entirely.
3. Find or create the `to_year` bucket (insert it in sorted year order if new). Add the track object. Increment count.
4. Write the updated JSON back to `favorites.json` using **atomic rename**: write to `favorites.json.tmp` first, then rename to `favorites.json`. Always use `ensure_ascii=False` when calling `json.dump`/`json.dumps` to preserve literal UTF-8 characters.
5. Append a correction entry to `overrides.json` (create the file with a JSON array if it doesn't exist yet):
   ```json
   { "track": "Track Name", "artist": "Artist", "from_year": YYYY, "to_year": YYYY }
   ```
   Keep `overrides.json` as a valid JSON array throughout.

Confirm the change briefly: `Moved "Track Name" from YYYY → YYYY.`

Then continue to the next track.

## Step 5 — End of year

After the last track (or after quit), report a summary:

```
Session complete. X correction(s) made in year YYYY.
```

If quit was used mid-year, note how many tracks were reviewed vs. total.
