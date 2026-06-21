<?php
/**
 * Restaurant POS — JSON API
 * All responses: { "success": bool, "data": mixed } or { "success": false, "error": string }
 *
 * GET  actions:  get_tables, get_categories, get_menu, get_order,
 *                get_active_order, dashboard_stats, low_stock, recent_orders
 * POST actions:  add_item, update_item, remove_item, update_order_status,
 *                cancel_order, checkout, set_table_status
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/settings.php';

header('Content-Type: application/json; charset=utf-8');

// ── Auth gate ───────────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'error' => 'Unauthenticated']));
}

// ── Route ────────────────────────────────────────────────────────────────────
$db     = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

// Parse JSON body for POST requests
$body = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true) ?? [];
    $action = $action ?: trim($body['action'] ?? '');
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function ok(mixed $data = null): never
{
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function fail(string $msg, int $code = 400): never
{
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

function requireFields(array $body, array $fields): void
{
    foreach ($fields as $f) {
        if (!isset($body[$f]) || $body[$f] === '') {
            fail("Missing required field: $f");
        }
    }
}

/**
 * Recalculate order totals from order_items, update the orders row, and
 * return the full order payload (header + items).
 */
function recalcAndFetch(Database $db, int $orderId): array
{
    $subtotal = (float) $db->fetchScalar(
        'SELECT COALESCE(SUM(subtotal), 0) FROM order_items WHERE order_id = ?',
        [$orderId]
    );

    $order = $db->fetchOne('SELECT tax_rate, discount_amount FROM orders WHERE id = ?', [$orderId]);
    $taxRate  = (float) ($order['tax_rate'] ?? Settings::taxRate());
    $discount = (float) ($order['discount_amount'] ?? 0);
    $tax      = round($subtotal * $taxRate / 100, 2);
    $total    = round(max(0, $subtotal + $tax - $discount), 2);

    $db->query(
        'UPDATE orders SET subtotal = ?, tax_amount = ?, total = ?, updated_at = NOW() WHERE id = ?',
        [$subtotal, $tax, $total, $orderId]
    );

    return fetchOrderWithItems($db, $orderId);
}

/** Return full order including items and table label. */
function fetchOrderWithItems(Database $db, int $orderId): array
{
    $order = $db->fetchOne(
        'SELECT o.*, t.table_number
           FROM orders o
           LEFT JOIN restaurant_tables t ON t.id = o.table_id
          WHERE o.id = ?',
        [$orderId]
    );
    if (!$order) fail('Order not found', 404);

    $order['items'] = $db->fetchAll(
        'SELECT oi.*, mi.track_stock FROM order_items oi
           JOIN menu_items mi ON mi.id = oi.menu_item_id
          WHERE oi.order_id = ?
          ORDER BY oi.id',
        [$orderId]
    );

    return $order;
}

// ── Router ────────────────────────────────────────────────────────────────────
switch ($action) {

    // ================================================================
    // GET: get_tables   (?section=X  optional filter)
    // ================================================================
    case 'get_tables':
        $section = trim($_GET['section'] ?? '');
        $sectionSql = $section ? "AND t.section = '$section'" : '';
        $rows = $db->fetchAll(
            "SELECT t.*,
                    o.id       AS order_id,
                    o.status   AS order_status,
                    o.total    AS order_total,
                    COUNT(DISTINCT oi.id) AS item_count,
                    r.id          AS reservation_id,
                    r.client_name,
                    TIME_FORMAT(r.from_time,'%H:%i') AS from_time,
                    TIME_FORMAT(r.to_time,  '%H:%i') AS to_time
               FROM restaurant_tables t
               LEFT JOIN orders o
                      ON o.table_id = t.id
                     AND o.status NOT IN ('paid','cancelled')
               LEFT JOIN order_items oi ON oi.order_id = o.id
               LEFT JOIN reservations r
                      ON r.table_id = t.id
                     AND r.status = 'confirmed'
                     AND r.id = (
                         SELECT r2.id FROM reservations r2
                          WHERE r2.table_id = t.id AND r2.status = 'confirmed'
                          ORDER BY r2.reserved_date ASC, r2.from_time ASC
                          LIMIT 1
                     )
              WHERE 1=1 $sectionSql
              GROUP BY t.id, o.id, o.status, o.total, r.id, r.client_name, r.from_time, r.to_time
              ORDER BY t.section, t.table_number"
        );
        ok($rows);

    // ================================================================
    // GET: get_categories
    // ================================================================
    case 'get_categories':
        ok($db->fetchAll(
            'SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order, name'
        ));

    // ================================================================
    // GET: get_menu   (?category_id=X)
    // ================================================================
    case 'get_menu':
        $catId = (int)($_GET['category_id'] ?? 0);
        if ($catId) {
            $items = $db->fetchAll(
                'SELECT m.*, c.name AS category_name
                   FROM menu_items m
                   JOIN categories c ON c.id = m.category_id
                  WHERE m.category_id = ?
                  ORDER BY m.name',
                [$catId]
            );
        } else {
            $items = $db->fetchAll(
                'SELECT m.*, c.name AS category_name
                   FROM menu_items m
                   JOIN categories c ON c.id = m.category_id
                  WHERE m.is_available = 1
                  ORDER BY c.sort_order, m.name'
            );
        }
        ok($items);

    // ================================================================
    // GET: get_order   (?order_id=X)
    // ================================================================
    case 'get_order':
        $orderId = (int)($_GET['order_id'] ?? 0);
        if (!$orderId) fail('order_id required');
        ok(fetchOrderWithItems($db, $orderId));

    // ================================================================
    // GET: get_active_order   (?table_id=X)
    // ================================================================
    case 'get_active_order':
        $tableId = (int)($_GET['table_id'] ?? 0);
        if (!$tableId) fail('table_id required');
        $order = $db->fetchOne(
            "SELECT id FROM orders
              WHERE table_id = ? AND status NOT IN ('paid','cancelled')
              ORDER BY created_at DESC LIMIT 1",
            [$tableId]
        );
        if (!$order) ok(null);
        ok(fetchOrderWithItems($db, (int)$order['id']));

    // ================================================================
    // GET: dashboard_stats
    // ================================================================
    case 'dashboard_stats':
        $todaySales = $db->fetchScalar(
            "SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = CURDATE() AND status = 'paid'"
        );
        $todayOrders = $db->fetchScalar(
            "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE() AND status != 'cancelled'"
        );
        $occupiedTables = $db->fetchScalar(
            "SELECT COUNT(*) FROM restaurant_tables WHERE status = 'occupied'"
        );
        $totalTables = $db->fetchScalar('SELECT COUNT(*) FROM restaurant_tables');
        $lowStockCount = $db->fetchScalar(
            'SELECT COUNT(*) FROM inventory WHERE current_stock <= min_alert_level'
        );
        ok([
            'today_sales'     => (float) $todaySales,
            'today_orders'    => (int)   $todayOrders,
            'occupied_tables' => (int)   $occupiedTables,
            'total_tables'    => (int)   $totalTables,
            'low_stock_count' => (int)   $lowStockCount,
        ]);

    // ================================================================
    // GET: low_stock
    // ================================================================
    case 'low_stock':
        $items = $db->fetchAll(
            'SELECT *, ROUND(current_stock / NULLIF(min_alert_level,0) * 100, 1) AS stock_pct
               FROM inventory
              WHERE current_stock <= min_alert_level
              ORDER BY stock_pct ASC
              LIMIT 20'
        );
        ok($items);

    // ================================================================
    // GET: recent_orders
    // ================================================================
    case 'recent_orders':
        $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $orders = $db->fetchAll(
            "SELECT o.id, o.status, o.total, o.order_type, o.table_id, o.created_at,
                    t.table_number,
                    COUNT(oi.id) AS item_count
               FROM orders o
               LEFT JOIN restaurant_tables t  ON t.id  = o.table_id
               LEFT JOIN order_items oi        ON oi.order_id = o.id
              WHERE DATE(o.created_at) = CURDATE()
              GROUP BY o.id
              ORDER BY o.created_at DESC
              LIMIT ?",
            [$limit]
        );
        ok($orders);

    // ================================================================
    // POST: add_item
    // Creates the order if order_id is absent.
    // If the item already exists in the order, increments qty.
    // ================================================================
    case 'add_item':
        requireFields($body, ['menu_item_id']);

        $menuItemId = (int)$body['menu_item_id'];
        $quantity   = max(1, (int)($body['quantity'] ?? 1));
        $tableId    = isset($body['table_id'])  ? (int)$body['table_id']  : null;
        $orderId    = isset($body['order_id'])  ? (int)$body['order_id']  : null;
        $orderType  = in_array($body['order_type'] ?? '', ['dine_in','takeaway']) ? $body['order_type'] : 'dine_in';

        // Validate menu item
        $menuItem = $db->fetchOne(
            'SELECT id, name, price, is_available, track_stock, stock_count FROM menu_items WHERE id = ?',
            [$menuItemId]
        );
        if (!$menuItem) fail('Menu item not found', 404);
        if (!$menuItem['is_available']) fail('Item is currently unavailable');

        // Stock check for directly tracked items
        if ($menuItem['track_stock'] && $menuItem['stock_count'] !== null && $menuItem['stock_count'] < $quantity) {
            fail("Only {$menuItem['stock_count']} portions available");
        }

        $db->beginTransaction();
        try {
            // Create order if needed
            if (!$orderId) {
                $db->query(
                    'INSERT INTO orders (table_id, user_id, order_type, status, tax_rate, created_at, updated_at)
                     VALUES (?, ?, ?, "pending", ?, NOW(), NOW())',
                    [$tableId, $_SESSION['user_id'], $orderType, Settings::taxRate()]
                );
                $orderId = $db->lastInsertId();

                // Mark table occupied
                if ($tableId) {
                    $db->query(
                        "UPDATE restaurant_tables SET status = 'occupied', updated_at = NOW() WHERE id = ?",
                        [$tableId]
                    );
                }
            }

            // Check if item already in this order
            $existing = $db->fetchOne(
                'SELECT id, quantity FROM order_items WHERE order_id = ? AND menu_item_id = ?',
                [$orderId, $menuItemId]
            );

            if ($existing) {
                $newQty     = $existing['quantity'] + $quantity;
                $newSubtotal = $menuItem['price'] * $newQty;
                $db->query(
                    'UPDATE order_items SET quantity = ?, subtotal = ? WHERE id = ?',
                    [$newQty, $newSubtotal, $existing['id']]
                );
            } else {
                $subtotal = $menuItem['price'] * $quantity;
                $db->query(
                    'INSERT INTO order_items (order_id, menu_item_id, item_name, quantity, unit_price, subtotal, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW())',
                    [$orderId, $menuItemId, $menuItem['name'], $quantity, $menuItem['price'], $subtotal]
                );
            }

            $result = recalcAndFetch($db, $orderId);
            $db->commit();
            ok($result);
        } catch (\Exception $e) {
            $db->rollback();
            fail('Failed to add item: ' . $e->getMessage());
        }

    // ================================================================
    // POST: update_item   { order_item_id, quantity }
    // ================================================================
    case 'update_item':
        requireFields($body, ['order_item_id', 'quantity']);

        $itemId  = (int)$body['order_item_id'];
        $qty     = max(1, (int)$body['quantity']);
        $oi      = $db->fetchOne('SELECT * FROM order_items WHERE id = ?', [$itemId]);
        if (!$oi) fail('Order item not found', 404);

        $db->query(
            'UPDATE order_items SET quantity = ?, subtotal = quantity * unit_price WHERE id = ?',
            [$qty, $itemId]
        );
        // Recalculate subtotal correctly
        $db->query(
            'UPDATE order_items SET subtotal = ? * unit_price WHERE id = ?',
            [$qty, $itemId]
        );
        ok(recalcAndFetch($db, (int)$oi['order_id']));

    // ================================================================
    // POST: remove_item   { order_item_id }
    // ================================================================
    case 'remove_item':
        requireFields($body, ['order_item_id']);

        $itemId = (int)$body['order_item_id'];
        $oi     = $db->fetchOne('SELECT order_id FROM order_items WHERE id = ?', [$itemId]);
        if (!$oi) fail('Order item not found', 404);
        $orderId = (int)$oi['order_id'];

        $db->query('DELETE FROM order_items WHERE id = ?', [$itemId]);
        ok(recalcAndFetch($db, $orderId));

    // ================================================================
    // POST: update_order_status   { order_id, status }
    // ================================================================
    case 'update_order_status':
        requireFields($body, ['order_id', 'status']);

        $orderId   = (int)$body['order_id'];
        $newStatus = $body['status'];
        $allowed   = ['pending','kitchen','served','paid','cancelled'];
        if (!in_array($newStatus, $allowed)) fail('Invalid status');

        $order = $db->fetchOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$order) fail('Order not found', 404);

        $db->query(
            "UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?",
            [$newStatus, $orderId]
        );

        ok(fetchOrderWithItems($db, $orderId));

    // ================================================================
    // POST: cancel_order   { order_id }
    // ================================================================
    case 'cancel_order':
        requireFields($body, ['order_id']);

        $orderId = (int)$body['order_id'];
        $order   = $db->fetchOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$order) fail('Order not found', 404);
        if ($order['status'] === 'paid') fail('Cannot cancel a paid order');

        $db->beginTransaction();
        try {
            $db->query("UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = ?", [$orderId]);
            if ($order['table_id']) {
                $db->query("UPDATE restaurant_tables SET status = 'open', updated_at = NOW() WHERE id = ?", [$order['table_id']]);
            }
            $db->commit();
            ok(['order_id' => $orderId, 'status' => 'cancelled']);
        } catch (\Exception $e) {
            $db->rollback();
            fail('Cancel failed: ' . $e->getMessage());
        }

    // ================================================================
    // POST: checkout   { order_id, payment_method, amount_tendered, customer_name? }
    // ================================================================
    case 'checkout':
        requireFields($body, ['order_id', 'payment_method']);

        $orderId       = (int)$body['order_id'];
        $payMethod     = $body['payment_method'];
        $customerName  = trim($body['customer_name'] ?? '');
        $validMethods  = ['cash','card','other'];
        $tenderCur     = in_array($body['tender_currency'] ?? 'USD', ['USD','LBP']) ? $body['tender_currency'] : 'USD';

        if (!in_array($payMethod, $validMethods)) fail('Invalid payment method');

        $order = $db->fetchOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$order)                      fail('Order not found', 404);
        if ($order['status'] === 'paid')  fail('Order already paid');
        if ($order['status'] === 'cancelled') fail('Order is cancelled');

        $total    = (float)$order['total'];
        $lbpRate  = Settings::lbpRate();

        // Resolve tendered amounts in both currencies
        if ($tenderCur === 'LBP' && $lbpRate > 0) {
            $tenderedLBP = (int)($body['amount_tendered_lbp'] ?? 0);
            $tendered    = round($tenderedLBP / $lbpRate, 2);
        } else {
            $tendered    = (float)($body['amount_tendered'] ?? 0);
            $tenderedLBP = $lbpRate > 0 ? (int)round($tendered * $lbpRate) : 0;
        }

        $changeDue    = round(max(0, $tendered - $total), 2);
        $changeDueLBP = $lbpRate > 0 ? (int)round($changeDue * $lbpRate) : 0;

        if ($payMethod === 'cash' && $tendered < $total) {
            fail(sprintf('Amount tendered (%s%.2f) is less than total (%s%.2f)', CURRENCY_SYMBOL, $tendered, CURRENCY_SYMBOL, $total));
        }

        $db->beginTransaction();
        try {
            // Mark order paid
            $db->query(
                "UPDATE orders
                    SET status = 'paid', payment_method = ?, amount_tendered = ?,
                        change_due = ?, amount_tendered_lbp = ?, change_due_lbp = ?,
                        customer_name = ?, updated_at = NOW()
                  WHERE id = ?",
                [$payMethod, $tendered, $changeDue, $tenderedLBP, $changeDueLBP,
                 $customerName ?: null, $orderId]
            );

            // Free up the table
            if ($order['table_id']) {
                $db->query(
                    "UPDATE restaurant_tables SET status = 'open', updated_at = NOW() WHERE id = ?",
                    [$order['table_id']]
                );
            }

            // Deduct inventory via recipes
            $orderItems = $db->fetchAll(
                'SELECT menu_item_id, quantity FROM order_items WHERE order_id = ?',
                [$orderId]
            );
            foreach ($orderItems as $oi) {
                // Recipe-based deduction
                $ingredients = $db->fetchAll(
                    'SELECT inventory_id, quantity_needed FROM menu_item_ingredients WHERE menu_item_id = ?',
                    [$oi['menu_item_id']]
                );
                foreach ($ingredients as $ing) {
                    $deduct = $ing['quantity_needed'] * $oi['quantity'];
                    $db->query(
                        'UPDATE inventory SET current_stock = GREATEST(0, current_stock - ?), updated_at = NOW() WHERE id = ?',
                        [$deduct, $ing['inventory_id']]
                    );
                }

                // Direct portion-count deduction
                $mi = $db->fetchOne('SELECT track_stock, stock_count FROM menu_items WHERE id = ?', [$oi['menu_item_id']]);
                if ($mi && $mi['track_stock'] && $mi['stock_count'] !== null) {
                    $db->query(
                        'UPDATE menu_items SET stock_count = GREATEST(0, stock_count - ?) WHERE id = ?',
                        [$oi['quantity'], $oi['menu_item_id']]
                    );
                }
            }

            $db->commit();

            // Build receipt payload
            $table = $order['table_id']
                ? $db->fetchOne('SELECT table_number FROM restaurant_tables WHERE id = ?', [$order['table_id']])
                : null;

            $items = $db->fetchAll(
                'SELECT item_name, quantity, unit_price, subtotal FROM order_items WHERE order_id = ?',
                [$orderId]
            );

            ok([
                'order_id'           => $orderId,
                'table_number'       => $table['table_number'] ?? null,
                'subtotal'           => $order['subtotal'],
                'tax_amount'         => $order['tax_amount'],
                'total'              => $total,
                'payment_method'     => $payMethod,
                'tender_currency'    => $tenderCur,
                'amount_tendered'    => $tendered,
                'amount_tendered_lbp'=> $tenderedLBP,
                'change_due'         => $changeDue,
                'change_due_lbp'     => $changeDueLBP,
                'lbp_rate'           => $lbpRate,
                'items'              => $items,
            ]);

        } catch (\Exception $e) {
            $db->rollback();
            fail('Checkout failed: ' . $e->getMessage());
        }

    // ================================================================
    // POST: set_table_status   { table_id, status }
    // ================================================================
    case 'set_table_status':
        requireFields($body, ['table_id', 'status']);

        $tableId   = (int)$body['table_id'];
        $newStatus = $body['status'];
        $allowed   = ['open','occupied','reserved','cleaning'];
        if (!in_array($newStatus, $allowed)) fail('Invalid status');

        $db->query(
            "UPDATE restaurant_tables SET status = ?, updated_at = NOW() WHERE id = ?",
            [$newStatus, $tableId]
        );
        ok(['table_id' => $tableId, 'status' => $newStatus]);

    // ================================================================
    // POST: add_reservation
    // { table_id, client_name, reserved_date, from_time, to_time, notes? }
    // ================================================================
    case 'add_reservation':
        requireFields($body, ['table_id', 'client_name', 'reserved_date', 'from_time', 'to_time']);

        $tableId     = (int)$body['table_id'];
        $clientName  = trim($body['client_name']);
        $resDate     = $body['reserved_date'];
        $fromTime    = $body['from_time'];
        $toTime      = $body['to_time'];
        $notes       = trim($body['notes'] ?? '');

        if (!$tableId) fail('Invalid table_id');
        if (!$clientName) fail('client_name is required');

        $table = $db->fetchOne('SELECT id, status FROM restaurant_tables WHERE id = ?', [$tableId]);
        if (!$table) fail('Table not found', 404);
        if ($table['status'] === 'occupied') fail('Table is currently occupied');

        $db->beginTransaction();
        try {
            $db->query(
                'INSERT INTO reservations (table_id, client_name, reserved_date, from_time, to_time, notes, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, "confirmed", ?)',
                [$tableId, $clientName, $resDate, $fromTime, $toTime, $notes ?: null, $_SESSION['user_id']]
            );
            $resId = $db->lastInsertId();

            $db->query(
                "UPDATE restaurant_tables SET status = 'reserved', updated_at = NOW() WHERE id = ?",
                [$tableId]
            );
            $db->commit();
            ok(['reservation_id' => $resId]);
        } catch (\Exception $e) {
            $db->rollback();
            fail('Failed to save reservation: ' . $e->getMessage());
        }

    // ================================================================
    // GET: get_table_reservations   (?table_id=X)
    // ================================================================
    case 'get_table_reservations':
        $tableId = (int)($_GET['table_id'] ?? 0);
        if (!$tableId) fail('table_id required');
        $rows = $db->fetchAll(
            "SELECT id, client_name, reserved_date, status, notes,
                    TIME_FORMAT(from_time,'%H:%i') AS from_time,
                    TIME_FORMAT(to_time,  '%H:%i') AS to_time
               FROM reservations
              WHERE table_id = ? AND status = 'confirmed'
              ORDER BY reserved_date ASC, from_time ASC",
            [$tableId]
        );
        ok($rows);

    // ================================================================
    // POST: cancel_reservation   { reservation_id }
    // ================================================================
    case 'cancel_reservation':
        requireFields($body, ['reservation_id']);

        $resId = (int)$body['reservation_id'];
        $res   = $db->fetchOne('SELECT * FROM reservations WHERE id = ?', [$resId]);
        if (!$res) fail('Reservation not found', 404);

        $db->beginTransaction();
        try {
            $db->query(
                "UPDATE reservations SET status = 'cancelled' WHERE id = ?",
                [$resId]
            );

            // Only free the table if it has no other active reservations
            $remaining = (int)$db->fetchScalar(
                "SELECT COUNT(*) FROM reservations WHERE table_id = ? AND status = 'confirmed' AND id != ?",
                [$res['table_id'], $resId]
            );
            if ($remaining === 0) {
                $db->query(
                    "UPDATE restaurant_tables SET status = 'open', updated_at = NOW() WHERE id = ?",
                    [$res['table_id']]
                );
            }
            $db->commit();
            ok(['reservation_id' => $resId, 'status' => 'cancelled']);
        } catch (\Exception $e) {
            $db->rollback();
            fail('Cancel failed: ' . $e->getMessage());
        }

    // ================================================================
    // Fallback
    // ================================================================
    default:
        fail("Unknown action: $action", 404);
}

