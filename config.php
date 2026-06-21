<?php
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'restaurant_pos');
define('DB_USER',    'root');
define('DB_PASS',    '');
define('DB_CHARSET', 'utf8mb4');

define('RESTAURANT_NAME',     'Padel07');
define('RESTAURANT_SUBTITLE', 'Hasbaya Padel Club');
define('TAX_RATE',         0.0);   // fallback only — live value stored in settings table
define('CURRENCY_SYMBOL', '$');
define('CURRENCY_CODE',   'USD');

define('SESSION_NAME',    'restaurant_pos_session');
define('SESSION_LIFETIME', 28800);

// GitHub repository for auto-updates (set to your repo, or leave as-is to disable)
define('GITHUB_OWNER', 'boureslan-omar');
define('GITHUB_REPO',  'restaurant-pos');

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params(SESSION_LIFETIME);
    session_start();
}

// License enforcement — runs on every web request except the license page itself
if (PHP_SAPI !== 'cli') {
    $__page = basename($_SERVER['PHP_SELF'] ?? '');
    if (!in_array($__page, ['license.php', 'license_generator.php'])) {
        require_once __DIR__ . '/includes/license.php';
        if (!License::isValid()) {
            if ($__page === 'api.php') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'License invalid or missing — contact your vendor']);
                exit;
            }
            header('Location: /restaurant-pos/license.php');
            exit;
        }
    }
    unset($__page);
}