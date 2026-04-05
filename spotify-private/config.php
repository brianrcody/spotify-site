<?php
/**
 * Spotify Personal Site — Configuration
 *
 * Fill in SPOTIFY_CLIENT_ID after creating your app at developer.spotify.com/dashboard.
 * Fill in SPOTIFY_REFRESH_TOKEN after completing the one-time PKCE setup flow.
 * Set USER_HOME to your home directory path on the server.
 */

// Absolute path to your home directory (no trailing slash)
define('USER_HOME', '/home/CHANGEME');

define('SPOTIFY_CLIENT_ID',     'YOUR_CLIENT_ID_HERE');
define('SPOTIFY_REFRESH_TOKEN', 'YOUR_REFRESH_TOKEN_HERE');
define('SPOTIFY_REDIRECT_URI',  'https://yourdomain.com/spotify/setup/callback.php');

// Passphrase gate for setup scripts — change this before deploying
define('SETUP_PASSPHRASE', 'change-me-to-something-random');

define('PRIVATE_DIR', __DIR__);
define('DATA_DIR',    __DIR__ . '/data');
