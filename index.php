<?php
require_once __DIR__ . '/config.php';
header('Location: ' . (empty($_SESSION['user_id']) ? 'login.php' : 'dashboard.php'));
exit;
