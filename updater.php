<?php
/**
 * Update checker — called from start_pos.bat:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\restaurant-pos\updater.php
 *
 * Checks GitHub for a newer release and applies it automatically.
 * Configure GITHUB_OWNER and GITHUB_REPO in config.php.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Access denied — run from CLI only.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/updater.php';

Updater::checkAndApply(verbose: true);
