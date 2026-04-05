<?php
require_once dirname(__DIR__, 2) . '/spotify-private/config.php';
require_once PRIVATE_DIR . '/lib/spotify.php';
serve_data_file('top-tracks.json');
