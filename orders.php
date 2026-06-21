<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$db = Database::getInstance();

// ── Filters ───────────────────────────────────────────────────────────────────
$status   = $_GET['status']   ?? '';
$dateFrom = $_GET['from']     ?? date('Y-m-d');
$dateTo   = $_GET['to']       ?? date('Y-m-d');
$type     = $_GET['type']     ?? '';

$params = [$dateFrom, $dateTo];
$where  = 'WHERE DATE(o.created_at) BETWEEN ? AND ?';

if ($status) { $where .= ' AND o.status = ?'; $params[] = $status; }
if ($type)   { $where .= ' AND o.order_type = ?'; $params[] = $type; }

$orders = $db->fetchAll(
    "SELECT o.*, t.table_number,
            COUNT(oi.id) AS item_count,
            u.name AS cashier_name
       FROM orders o
       LEFT JOIN restaurant_tables t ON t.id = o.table_id
       LEFT JOIN order_items oi ON oi.order_id = o.id
       LEFT JOIN users u ON u.id = o.user_id
      $where
      GROUP BY o.id
      ORDER BY o.created_at DESC
      LIMIT 200",
    $params
);

$summary = $db->fetchOne(
    "SELECT COUNT(*) AS total_orders,
            COALESCE(SUM(CASE WHEN status='paid' THEN total END), 0) AS total_revenue,
            COALESCE(SUM(CASE WHEN status='paid' THEN 1 END), 0)    AS paid_count,
            COALESCE(SUM(CASE WHEN status='cancelled' THEN 1 END), 0) AS cancelled_count
       FROM orders
      WHERE DATE(created_at) BETWEEN ? AND ?",
    [$dateFrom, $dateTo]
);

$activePage = 'orders';
$pageTitle  = 'Orders — ' . RESTAURANT_NAME;
include __DIR__ . '/includes/header.php';

$statusStyles = [
    'pending'   => 'bg-yellow-100 text-yellow-700',
    'kitchen'   => 'bg-orange-100 text-orange-700',
    'served'    => 'bg-blue-100 text-blue-700',
    'paid'      => 'bg-emerald-100 text-emerald-700',
    'cancelled' => 'bg-slate-100 text-slate-500',
];
?>

<!-- TOP BAR -->
<header class="bg-white border-b border-slate-200 px-6 py-3.5 flex items-center justify-between flex-shrink-0">
    <div>
        <h1 class="font-bold text-lg text-slate-800">Order History</h1>
        <p class="text-xs text-slate-400"><?= count($orders) ?> orders · <?= $dateFrom === $dateTo ? $dateFrom : "$dateFrom → $dateTo" ?></p>
    </div>
</header>

<!-- MAIN -->
<div class="flex-1 overflow-y-auto p-5 space-y-4">

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <?php
        $cards = [
            ['label' => 'Total Orders', 'value' => $summary['total_orders'],   'color' => 'text-slate-800',   'bg' => 'bg-slate-100 text-slate-500',    'icon' => 'fa-receipt'],
            ['label' => 'Revenue',      'value' => CURRENCY_SYMBOL.number_format($summary['total_revenue'],2), 'color' => 'text-emerald-700', 'bg' => 'bg-emerald-100 text-emerald-600', 'icon' => 'fa-dollar-sign'],
            ['label' => 'Paid',         'value' => $summary['paid_count'],     'color' => 'text-emerald-700', 'bg' => 'bg-emerald-100 text-emerald-600', 'icon' => 'fa-check-circle'],
            ['label' => 'Cancelled',    'value' => $summary['cancelled_count'],'color' => 'text-rose-700',   'bg' => 'bg-rose-100 text-rose-500',       'icon' => 'fa-circle-xmark'],
        ];
        foreach ($cards as $c): ?>
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs text-slate-500 font-medium"><?= $c['label'] ?></span>
                <span class="<?= $c['bg'] ?> w-7 h-7 rounded-lg flex items-center justify-center text-xs">
                    <i class="fa-solid <?= $c['icon'] ?>"></i>
                </span>
            </div>
            <p class="text-xl font-bold <?= $c['color'] ?>"><?= $c['value'] ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-white rounded-xl border border-slate-200 px-4 py-3 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">From</label>
            <input type="date" name="from" value="<?= $dateFrom ?>" class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm outline-none">
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">To</label>
            <input type="date" name="to" value="<?= $dateTo ?>" class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm outline-none">
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">Status</label>
            <select name="status" class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm outline-none">
                <option value="">All</option>
                <?php foreach (['pending','kitchen','served','paid','cancelled'] as $s): ?>
                <option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">Type</label>
            <select name="type" class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm outline-none">
                <option value="">All</option>
                <option value="dine_in"  <?= $type==='dine_in'?'selected':'' ?>>Dine In</option>
                <option value="takeaway" <?= $type==='takeaway'?'selected':'' ?>>Takeaway</option>
            </select>
        </div>
        <button type="submit" class="text-black text-sm px-4 py-2 rounded-lg transition font-bold"
                style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">Filter</button>
        <a href="orders.php" class="text-slate-400 text-sm px-3 py-2 hover:text-slate-600">Reset</a>
    </form>

    <!-- Orders Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">#</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Table / Type</th>
                    <th class="text-center px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Items</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Total</th>
                    <th class="text-center px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Payment</th>
                    <th class="text-center px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Status</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Time</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if (empty($orders)): ?>
                <tr><td colspan="8" class="text-center py-10 text-slate-400">No orders found for the selected period.</td></tr>
                <?php else: ?>
                <?php foreach ($orders as $o):
                    $isActive = !in_array($o['status'], ['paid','cancelled']);
                ?>
                <tr class="hover:bg-slate-50 transition">
                    <td class="px-5 py-3 font-semibold text-slate-700">#<?= $o['id'] ?></td>
                    <td class="px-4 py-3">
                        <p class="font-medium text-slate-800"><?= $o['table_number'] ? 'Table ' . htmlspecialchars($o['table_number']) : 'Takeaway' ?></p>
                        <?php if ($o['customer_name']): ?><p class="text-xs text-slate-400"><?= htmlspecialchars($o['customer_name']) ?></p><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-slate-500"><?= $o['item_count'] ?></td>
                    <td class="px-4 py-3 text-right font-bold text-slate-700"><?= CURRENCY_SYMBOL ?><?= number_format($o['total'], 2) ?></td>
                    <td class="px-4 py-3 text-center text-slate-500 capitalize"><?= $o['payment_method'] ?? '—' ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $statusStyles[$o['status']] ?? '' ?>">
                            <?= ucfirst($o['status']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-500 text-xs"><?= date('H:i', strtotime($o['created_at'])) ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1">
                            <?php if ($isActive): ?>
                            <a href="pos.php?table_id=<?= $o['table_id'] ?>&order_id=<?= $o['id'] ?>"
                               class="px-2.5 py-1.5 rounded-lg text-xs font-medium transition"
                               style="background:#f2fce0; color:#436800;"
                               onmouseover="this.style.background='#e0f7b3'" onmouseout="this.style.background='#f2fce0'">
                                Open
                            </a>
                            <button onclick="openOrderDrawer(<?= $o['id'] ?>, true)"
                                    class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-xs font-medium transition">
                                Edit
                            </button>
                            <?php elseif ($o['status'] === 'paid'): ?>
                            <button onclick="openOrderDrawer(<?= $o['id'] ?>, false)"
                                    class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-xs font-medium transition">
                                View
                            </button>
                            <button onclick="openOrderDrawer(<?= $o['id'] ?>, true)"
                                    class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-xs font-medium transition">
                                Edit
                            </button>
                            <button onclick="openReceiptPopup(<?= $o['id'] ?>)"
                                    class="w-7 h-7 bg-slate-100 hover:bg-slate-200 text-slate-500 rounded-lg text-xs flex items-center justify-center transition" title="Print receipt">
                                <i class="fa-solid fa-print"></i>
                            </button>
                            <?php else: ?>
                            <button onclick="openOrderDrawer(<?= $o['id'] ?>, false)"
                                    class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-xs font-medium transition">
                                View
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════ ORDER EDIT DRAWER ═══════════════ -->
<!-- Backdrop -->
<div id="drawer-backdrop" onclick="closeDrawer()"
     class="hidden fixed inset-0 bg-black/30 z-40"></div>

<!-- Drawer panel -->
<div id="order-drawer"
     class="hidden fixed inset-y-0 right-0 z-50 flex flex-col bg-white shadow-2xl border-l border-slate-200"
     style="width:460px;">

    <!-- Header -->
    <div class="flex items-center justify-between px-5 py-4 border-b border-slate-200 flex-shrink-0">
        <div>
            <h3 class="font-bold text-slate-800 text-base" id="drawer-title">Order</h3>
            <p class="text-xs text-slate-400 mt-0.5" id="drawer-subtitle"></p>
        </div>
        <div class="flex items-center gap-2">
            <span id="drawer-status-badge" class="text-xs px-2 py-0.5 rounded-full font-medium"></span>
            <button onclick="closeDrawer()" class="text-slate-400 hover:text-slate-600 text-xl w-8 h-8 flex items-center justify-center rounded-lg hover:bg-slate-100 transition">&times;</button>
        </div>
    </div>

    <!-- Scrollable body -->
    <div class="flex-1 overflow-y-auto">

        <!-- Current items -->
        <div class="p-4 border-b border-slate-100">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3">Items</p>
            <div id="drawer-items">
                <p class="text-slate-300 text-sm text-center py-4">Loading…</p>
            </div>
        </div>

        <!-- Add item (edit mode only) -->
        <div id="drawer-add-section" class="hidden p-4 border-b border-slate-100">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-2">Add Item</p>
            <input type="text" id="drawer-search" placeholder="Search menu…"
                   oninput="filterMenuItems(this.value)"
                   class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none mb-2">
            <div id="drawer-cats" class="flex flex-wrap gap-1 mb-2"></div>
            <div id="drawer-menu" class="space-y-0.5 max-h-52 overflow-y-auto"></div>
        </div>

        <!-- Totals -->
        <div class="p-4">
            <div class="space-y-1.5 text-sm">
                <div class="flex justify-between text-slate-500">
                    <span>Subtotal</span><span id="d-subtotal">—</span>
                </div>
                <div class="flex justify-between text-slate-500">
                    <span id="d-tax-lbl">Tax</span><span id="d-tax">—</span>
                </div>
                <div class="flex justify-between font-black text-slate-800 text-base border-t border-slate-200 pt-2 mt-1">
                    <span>Total</span><span id="d-total">—</span>
                </div>
            </div>

            <!-- Payment summary (paid orders) -->
            <div id="d-payment" class="hidden mt-3 bg-emerald-50 border border-emerald-100 rounded-xl p-3 text-sm space-y-1">
                <div class="flex justify-between text-slate-600">
                    <span>Payment</span>
                    <span id="d-pay-method" class="font-semibold capitalize"></span>
                </div>
                <div id="d-cash-rows" class="hidden space-y-1">
                    <div class="flex justify-between text-slate-500">
                        <span>Tendered</span><span id="d-tendered"></span>
                    </div>
                    <div class="flex justify-between text-emerald-700 font-semibold">
                        <span>Change</span><span id="d-change"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer: view mode -->
    <div id="d-footer-view" class="flex gap-2 p-4 border-t border-slate-200 flex-shrink-0">
        <button onclick="printReceiptDrawer()"
                class="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold border border-slate-200 bg-white hover:bg-slate-50 transition text-slate-700">
            <i class="fa-solid fa-print"></i> Print
        </button>
        <button onclick="switchToEditMode()"
                class="flex-1 py-2.5 text-black rounded-xl text-sm font-bold transition"
                style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
            <i class="fa-solid fa-pen-to-square mr-1"></i> Edit Order
        </button>
        <button onclick="closeDrawer()"
                class="px-4 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-sm font-medium hover:bg-slate-50 transition">
            Close
        </button>
    </div>
    <!-- Footer: edit mode -->
    <div id="d-footer-edit" class="hidden flex gap-2 p-4 border-t border-slate-200 flex-shrink-0">
        <button onclick="printReceiptDrawer()"
                class="flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-bold border border-slate-200 bg-white hover:bg-slate-50 transition text-slate-700">
            <i class="fa-solid fa-print"></i> Print
        </button>
        <button onclick="closeDrawer()"
                class="flex-1 py-2.5 text-black rounded-xl text-sm font-bold transition"
                style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
            <i class="fa-solid fa-check mr-1"></i> Done
        </button>
    </div>
</div>

<script>
const CS = '<?= CURRENCY_SYMBOL ?>';
let _orderId   = null;
let _editMode  = false;
let _menuCache = null;
let _catCache  = null;
let _selCat    = null;

// ── Drawer open/close ─────────────────────────────────────────────────────────
async function openOrderDrawer(orderId, editable = false) {
    _orderId  = orderId;
    _editMode = editable;
    document.getElementById('order-drawer').classList.remove('hidden');
    document.getElementById('drawer-backdrop').classList.remove('hidden');
    document.getElementById('drawer-items').innerHTML = '<p class="text-slate-300 text-sm text-center py-4">Loading…</p>';

    await refreshDrawerOrder();
    applyEditMode();

    if (!_menuCache) {
        const [mr, cr] = await Promise.all([
            fetch('api.php?action=get_menu').then(r => r.json()),
            fetch('api.php?action=get_categories').then(r => r.json()),
        ]);
        if (mr.success) _menuCache = mr.data;
        if (cr.success) _catCache  = cr.data;
    }
    renderCatChips();
    filterMenuItems('');
}

function switchToEditMode() {
    _editMode = true;
    applyEditMode();
    // Re-render items to show controls
    refreshDrawerOrder();
}

function applyEditMode() {
    document.getElementById('drawer-add-section').classList.toggle('hidden', !_editMode);
    document.getElementById('d-footer-view').classList.toggle('hidden',  _editMode);
    document.getElementById('d-footer-edit').classList.toggle('hidden', !_editMode);
}

function closeDrawer() {
    document.getElementById('order-drawer').classList.add('hidden');
    document.getElementById('drawer-backdrop').classList.add('hidden');
    _orderId  = null;
    _editMode = false;
}

// ── Fetch & render order ──────────────────────────────────────────────────────
async function refreshDrawerOrder() {
    const res  = await fetch(`api.php?action=get_order&order_id=${_orderId}`);
    const data = await res.json();
    if (data.success) renderDrawer(data.data);
}

function renderDrawer(o) {
    // Header
    document.getElementById('drawer-title').textContent    = `Order #${o.id}`;
    document.getElementById('drawer-subtitle').textContent =
        (o.table_number ? 'Table ' + o.table_number : 'Takeaway') +
        ' · ' + new Date(o.created_at.replace(' ','T')).toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit'});

    // Status badge
    const badge = document.getElementById('drawer-status-badge');
    const statusCls = {
        pending:'bg-yellow-100 text-yellow-700', kitchen:'bg-orange-100 text-orange-700',
        served:'bg-blue-100 text-blue-700', paid:'bg-emerald-100 text-emerald-700',
        cancelled:'bg-slate-100 text-slate-500'
    };
    badge.className = 'text-xs px-2 py-0.5 rounded-full font-medium ' + (statusCls[o.status] || '');
    badge.textContent = o.status.charAt(0).toUpperCase() + o.status.slice(1);

    // Items
    const itemsDiv = document.getElementById('drawer-items');
    if (!o.items || o.items.length === 0) {
        itemsDiv.innerHTML = '<p class="text-slate-400 text-sm text-center py-4">No items yet.</p>';
    } else {
        itemsDiv.innerHTML = o.items.map(i => `
            <div class="flex items-center gap-2 py-2.5 border-b border-slate-50 last:border-0">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-slate-800 truncate">${escHtml(i.item_name)}</p>
                    <p class="text-xs text-slate-400">${CS}${parseFloat(i.unit_price).toFixed(2)} each</p>
                </div>
                ${_editMode ? `
                <div class="flex items-center gap-1 flex-shrink-0">
                    <button onclick="changeQty(${i.id}, ${i.quantity - 1})"
                            class="w-6 h-6 rounded-md bg-slate-100 hover:bg-rose-50 hover:text-rose-500 text-slate-600 text-sm font-bold flex items-center justify-center transition">−</button>
                    <span class="w-7 text-center text-sm font-black text-slate-700">${i.quantity}</span>
                    <button onclick="changeQty(${i.id}, ${i.quantity + 1})"
                            class="w-6 h-6 rounded-md bg-slate-100 hover:bg-emerald-50 hover:text-emerald-600 text-slate-600 text-sm font-bold flex items-center justify-center transition">+</button>
                    <button onclick="removeItem(${i.id})"
                            class="w-6 h-6 rounded-md bg-rose-50 hover:bg-rose-100 text-rose-500 text-xs flex items-center justify-center transition ml-1" title="Remove">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>` : `
                <span class="text-sm text-slate-500 flex-shrink-0">×${i.quantity}</span>`}
                <div class="w-14 text-right flex-shrink-0">
                    <p class="text-sm font-bold text-slate-700">${CS}${parseFloat(i.subtotal).toFixed(2)}</p>
                </div>
            </div>`).join('');
    }

    // Totals
    document.getElementById('d-subtotal').textContent = CS + parseFloat(o.subtotal).toFixed(2);
    document.getElementById('d-tax-lbl').textContent  = `Tax (${parseFloat(o.tax_rate)}%)`;
    document.getElementById('d-tax').textContent      = CS + parseFloat(o.tax_amount).toFixed(2);
    document.getElementById('d-total').textContent    = CS + parseFloat(o.total).toFixed(2);

    // Payment block (paid orders only)
    const payDiv = document.getElementById('d-payment');
    if (o.status === 'paid' && o.payment_method) {
        payDiv.classList.remove('hidden');
        document.getElementById('d-pay-method').textContent = o.payment_method;
        const cashRows = document.getElementById('d-cash-rows');
        if (o.payment_method === 'cash' && parseFloat(o.amount_tendered) > 0) {
            cashRows.classList.remove('hidden');
            document.getElementById('d-tendered').textContent = CS + parseFloat(o.amount_tendered).toFixed(2);
            document.getElementById('d-change').textContent   = CS + parseFloat(o.change_due).toFixed(2);
        } else {
            cashRows.classList.add('hidden');
        }
    } else {
        payDiv.classList.add('hidden');
    }
}

// ── Item actions ──────────────────────────────────────────────────────────────
async function changeQty(orderItemId, newQty) {
    if (newQty < 1) {
        if (!confirm('Remove this item from the order?')) return;
        return removeItem(orderItemId);
    }
    const data = await apiPost({action:'update_item', order_item_id: orderItemId, quantity: newQty});
    if (data.success) renderDrawer(data.data);
    else showToast(data.error || 'Failed', 'error');
}

async function removeItem(orderItemId) {
    const data = await apiPost({action:'remove_item', order_item_id: orderItemId});
    if (data.success) renderDrawer(data.data);
    else showToast(data.error || 'Failed', 'error');
}

async function addItemToOrder(menuItemId) {
    if (!_orderId) return;
    const data = await apiPost({action:'add_item', menu_item_id: menuItemId, order_id: _orderId, quantity: 1});
    if (data.success) {
        renderDrawer(data.data);
        showToast('Item added');
    } else {
        showToast(data.error || 'Failed to add item', 'error');
    }
}

// ── Menu / category rendering ─────────────────────────────────────────────────
function renderCatChips() {
    const div = document.getElementById('drawer-cats');
    div.innerHTML =
        chip('All', null, _selCat === null) +
        (_catCache || []).map(c => chip(c.name, c.id, _selCat == c.id)).join('');
}

function chip(label, id, active) {
    const base = 'px-2.5 py-0.5 rounded-full text-xs font-medium border cursor-pointer transition ';
    const cls  = active
        ? base + 'border-green-400 text-green-700 ' : base + 'border-slate-200 text-slate-500 hover:border-slate-300 ';
    return `<span class="${cls}" style="${active?'background:#f2fce0':''}"
                  onclick="setCat(${id === null ? 'null' : id})">${escHtml(label)}</span>`;
}

function setCat(id) {
    _selCat = id;
    renderCatChips();
    filterMenuItems(document.getElementById('drawer-search').value);
}

function filterMenuItems(q) {
    const div = document.getElementById('drawer-menu');
    if (!_menuCache) { div.innerHTML = ''; return; }

    let items = _menuCache.filter(i => i.is_available == 1);
    if (_selCat) items = items.filter(i => i.category_id == _selCat);
    if (q.trim()) items = items.filter(i => i.name.toLowerCase().includes(q.toLowerCase()));

    if (items.length === 0) {
        div.innerHTML = '<p class="text-slate-400 text-xs py-2 text-center">No items match.</p>';
        return;
    }

    div.innerHTML = items.slice(0, 30).map(i => `
        <button type="button" onclick="addItemToOrder(${i.id})"
            class="w-full text-left flex items-center justify-between px-3 py-2 rounded-lg group hover:bg-slate-50 transition">
            <span class="text-sm text-slate-700 font-medium truncate">${escHtml(i.name)}</span>
            <span class="text-xs text-slate-400 ml-2 flex-shrink-0 flex items-center gap-1">
                ${CS}${parseFloat(i.price).toFixed(2)}
                <i class="fa-solid fa-circle-plus text-xs opacity-0 group-hover:opacity-100 transition" style="color:#76B900;"></i>
            </span>
        </button>`).join('');
}

// ── Print ─────────────────────────────────────────────────────────────────────
function printReceiptDrawer() {
    if (_orderId) openReceiptPopup(_orderId);
}

function openReceiptPopup(orderId) {
    window.open(`receipt.php?order_id=${orderId}`, 'receipt',
        'width=420,height=750,scrollbars=yes,resizable=yes');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
async function apiPost(body) {
    const res = await fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(body),
    });
    return res.json();
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
