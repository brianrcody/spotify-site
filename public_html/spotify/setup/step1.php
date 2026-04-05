<?php
/**
 * PKCE Setup — Step 1
 *
 * Generates a code verifier/challenge and outputs the Spotify authorization URL.
 * Access: protected by SETUP_PASSPHRASE query parameter.
 *
 * Usage: https://yourdomain.com/spotify/setup/step1.php?key=YOUR_PASSPHRASE
 */

require_once dirname(__DIR__, 2) . '/spotify-private/config.php';

// Passphrase gate
if (($_GET['key'] ?? '') !== SETUP_PASSPHRASE) {
    http_response_code(403);
    die('Forbidden');
}

// Generate PKCE code verifier (base64url-encoded 64 random bytes)
$verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');

// Generate code challenge (SHA-256 hash of verifier, base64url-encoded)
$challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

// Store verifier for callback
file_put_contents(PRIVATE_DIR . '/pkce-verifier.txt', $verifier);

$scopes = implode(' ', [
    'playlist-read-private',
    'user-top-read',
    'user-read-recently-played',
    'user-read-currently-playing',
    'user-read-playback-state',
]);

$auth_url = 'https://accounts.spotify.com/authorize?' . http_build_query([
    'client_id'             => SPOTIFY_CLIENT_ID,
    'response_type'         => 'code',
    'redirect_uri'          => SPOTIFY_REDIRECT_URI,
    'code_challenge_method' => 'S256',
    'code_challenge'        => $challenge,
    'scope'                 => $scopes,
]);

?>
<!DOCTYPE html>
<html>
<head><title>Spotify PKCE Setup — Step 1</title></head>
<body style="font-family: monospace; padding: 40px; background: #111; color: #eee;">
    <h2>Step 1: Authorize with Spotify</h2>
    <p>Click the link below to authorize your Spotify account:</p>
    <p><a href="<?= htmlspecialchars($auth_url) ?>" style="color: #1DB954;">
        Authorize on Spotify →
    </a></p>
    <p style="color: #888; font-size: 12px;">
        Code verifier stored. After authorizing, Spotify will redirect to callback.php.
    </p>
</body>
</html>
