<?php
/**
 * Upgrade script — run automatically by start_pos.bat when a new version is detected.
 *
 * HOW TO USE (for developer):
 *   1. Make your code changes and push the updated files to GitHub.
 *   2. Write the DB migrations / file patches below.
 *   3. Bump version.txt to the new version number.
 *   4. Commit and push:  git add -A && git commit -m "Release vX.X.X" && git push
 *
 * On next startup, customers will download and run this script automatically.
 *
 * This script runs as PHP CLI — full access to config.php, db.php, etc.
 */
if (PHP_SAPI !== 'cli') { die("Run from CLI only.\n"); }

$root = __DIR__;
require_once $root . '/config.php';
require_once $root . '/db.php';

$db      = Database::getInstance();
$current = trim((string)file_get_contents($root . '/version.txt'));

echo "=== Padel07 POS Upgrade ===\n";
echo "From: v$current\n";

// ============================================================
// v1.0.0 baseline — no migrations needed
// ============================================================

// Example for next release (uncomment and adapt):
// $db->query("ALTER TABLE orders ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL");
// echo "  + Added orders.notes column\n";

// ============================================================
// Always last: update version.txt
// ============================================================
$newVersion = '1.0.0'; // ← bump this for each release
file_put_contents($root . '/version.txt', $newVersion);
echo "To:   v$newVersion\n";
echo "Done!\n";
