<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings.php';
requireAuth();

header('Content-Type: application/json');

$drawerPort = Settings::get('drawer_port', '');
if (!$drawerPort) {
    echo json_encode(['success' => false, 'error' => 'No printer port configured in Settings']);
    exit;
}

// ESC/POS drawer-kick command: ESC p PIN ON_TIME OFF_TIME
// Pin 2 (most common), 100ms on, 250ms off
$kickCmd = "\x1B\x70\x00\x64\xFA";

$result = @file_put_contents($drawerPort, $kickCmd);
if ($result !== false) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => 'Could not write to ' . $drawerPort . ' — check port name and printer connection']);
}
