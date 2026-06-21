<?php
/**
 * Role-based access control helpers.
 * Include after config.php (session must already be started).
 */

function requireAuth(): void {
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function requireAdmin(): void {
    requireAuth();
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        header('Location: dashboard.php?err=access');
        exit;
    }
}

function isAdmin(): bool {
    return ($_SESSION['user_role'] ?? '') === 'admin';
}

function isCashier(): bool {
    return in_array($_SESSION['user_role'] ?? '', ['admin', 'cashier']);
}

/** Pages each role may access */
const ROLE_PAGES = [
    'admin'   => ['dashboard', 'pos', 'orders', 'menu', 'inventory', 'tables', 'users', 'reports', 'settings'],
    'cashier' => ['dashboard', 'pos', 'orders'],
    'waiter'  => ['dashboard', 'pos', 'orders'],
    'kitchen' => ['dashboard'],
];

function canAccess(string $page): bool {
    $role = $_SESSION['user_role'] ?? '';
    return in_array($page, ROLE_PAGES[$role] ?? []);
}

function navItemsForRole(): array {
    $role = $_SESSION['user_role'] ?? '';
    $allowed = ROLE_PAGES[$role] ?? [];
    $all = [
        'dashboard' => ['icon' => 'fa-table-cells-large', 'label' => 'Floor View',    'href' => 'dashboard.php'],
        'pos'       => ['icon' => 'fa-bag-shopping',       'label' => 'Takeout Order', 'href' => 'pos.php?type=takeaway'],
        'orders'    => ['icon' => 'fa-list-check',         'label' => 'Orders',        'href' => 'orders.php'],
        'menu'      => ['icon' => 'fa-utensils',           'label' => 'Menu',          'href' => 'menu.php'],
        'inventory' => ['icon' => 'fa-boxes-stacked',      'label' => 'Inventory',     'href' => 'inventory.php'],
        'tables'    => ['icon' => 'fa-chair',              'label' => 'Tables',        'href' => 'tables.php'],
        'users'     => ['icon' => 'fa-users',              'label' => 'Users',         'href' => 'users.php'],
        'reports'   => ['icon' => 'fa-chart-bar',          'label' => 'Reports',       'href' => 'reports.php'],
        'settings'  => ['icon' => 'fa-gear',               'label' => 'Settings',      'href' => 'settings.php'],
    ];
    return array_filter($all, fn($k) => in_array($k, $allowed), ARRAY_FILTER_USE_KEY);
}
