<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$db  = Database::getInstance();
$msg = '';
$err = '';

// ── Helpers ──────────────────────────────────────────────────────────────────
function parseBoxFields(array $post): array {
    $unit        = trim($post['unit'] ?? 'pcs');
    $unitsPerBox = ($unit === 'boxes' && !empty($post['units_per_box'])) ? max(1, (int)$post['units_per_box']) : null;
    $costPerBox  = ($unit === 'boxes' && isset($post['cost_per_box']))   ? (float)$post['cost_per_box']        : null;
    $costPerUnit = ($unitsPerBox && $costPerBox)
                    ? round($costPerBox / $unitsPerBox, 4)
                    : (float)($post['cost_per_unit'] ?? 0);
    $sellingPrice = isset($post['selling_price']) && $post['selling_price'] !== '' ? (float)$post['selling_price'] : null;
    return [$unitsPerBox, $costPerBox, $costPerUnit, $sellingPrice];
}

// ── Handle POST actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'add') {
        $name     = trim($_POST['name'] ?? '');
        $unit     = trim($_POST['unit'] ?? 'pcs');
        $stock    = (float)($_POST['current_stock'] ?? 0);
        $minAlert = (float)($_POST['min_alert_level'] ?? 0);
        $catId    = (int)($_POST['category_id'] ?? 0);
        [$unitsPerBox, $costPerBox, $costPerUnit, $sellingPrice] = parseBoxFields($_POST);

        if (!$name) { $err = 'Name is required.'; }
        else {
            $db->beginTransaction();
            try {
                $db->query(
                    'INSERT INTO inventory (name, unit, units_per_box, current_stock, min_alert_level, cost_per_unit, cost_per_box, selling_price)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$name, $unit, $unitsPerBox, $stock, $minAlert, $costPerUnit, $costPerBox, $sellingPrice]
                );
                $invId = $db->lastInsertId();

                if ($stock > 0) {
                    $totalCost = round($stock * ($unit === 'boxes' ? ($costPerBox ?? $costPerUnit) : $costPerUnit), 2);
                    $db->query(
                        'INSERT INTO purchase_log (inventory_id, qty_boxes, qty_units, cost_per_box, cost_per_unit, total_cost, notes, user_id)
                         VALUES (?,?,?,?,?,?,?,?)',
                        [
                            $invId,
                            $unit === 'boxes' ? $stock : null,
                            $unit === 'boxes' ? ($unitsPerBox ? $stock * $unitsPerBox : $stock) : $stock,
                            $costPerBox,
                            $costPerUnit,
                            $totalCost,
                            'Initial stock',
                            $_SESSION['user_id'],
                        ]
                    );
                }

                // Auto-create menu item when selling price + category provided
                $menuCreated = false;
                if ($sellingPrice > 0 && $catId > 0) {
                    $cat = $db->fetchOne('SELECT id FROM categories WHERE id=? AND is_active=1', [$catId]);
                    if ($cat) {
                        $db->query(
                            'INSERT INTO menu_items (category_id, name, price, is_available) VALUES (?,?,?,1)',
                            [$catId, $name, $sellingPrice]
                        );
                        $menuItemId = $db->lastInsertId();
                        // Link menu item → inventory item so COGS is calculable in reports
                        $db->query(
                            'INSERT INTO menu_item_ingredients (menu_item_id, inventory_id, quantity_needed) VALUES (?,?,1)',
                            [$menuItemId, $invId]
                        );
                        $menuCreated = true;
                    }
                }

                $db->commit();
                $msg = "\"$name\" added to inventory." . ($menuCreated ? ' Menu item created automatically.' : '');
            } catch (\Exception $e) {
                $db->rollback();
                $err = 'Failed to add item: ' . $e->getMessage();
            }
        }

    } elseif ($act === 'adjust') {
        $id       = (int)($_POST['id'] ?? 0);
        $change   = (float)($_POST['change'] ?? 0);
        $notes    = trim($_POST['notes'] ?? '');
        $row      = $db->fetchOne('SELECT * FROM inventory WHERE id = ?', [$id]);
        if ($row) {
            $newStock = max(0, $row['current_stock'] + $change);
            $db->beginTransaction();
            try {
                $db->query('UPDATE inventory SET current_stock = ?, updated_at = NOW() WHERE id = ?', [$newStock, $id]);

                if ($change > 0) {
                    $cpb       = $row['cost_per_box']  ?? null;
                    $cpu       = (float)$row['cost_per_unit'];
                    $totalCost = round($change * ($row['unit'] === 'boxes' ? ($cpb ?? $cpu) : $cpu), 2);
                    $db->query(
                        'INSERT INTO purchase_log (inventory_id, qty_boxes, qty_units, cost_per_box, cost_per_unit, total_cost, notes, user_id)
                         VALUES (?,?,?,?,?,?,?,?)',
                        [
                            $id,
                            $row['unit'] === 'boxes' ? $change : null,
                            $row['unit'] === 'boxes' ? ($row['units_per_box'] ? $change * $row['units_per_box'] : $change) : $change,
                            $cpb,
                            $cpu,
                            $totalCost,
                            $notes ?: 'Stock adjustment',
                            $_SESSION['user_id'],
                        ]
                    );
                }
                $db->commit();
                $msg = "Stock for \"{$row['name']}\" updated to $newStock.";
            } catch (\Exception $e) {
                $db->rollback();
                $err = 'Adjustment failed.';
            }
        }

    } elseif ($act === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $unit     = trim($_POST['unit'] ?? 'pcs');
        $stock    = (float)($_POST['current_stock'] ?? 0);
        $minAlert = (float)($_POST['min_alert_level'] ?? 0);
        [$unitsPerBox, $costPerBox, $costPerUnit, $sellingPrice] = parseBoxFields($_POST);

        if (!$name) { $err = 'Name is required.'; }
        else {
            $db->query(
                'UPDATE inventory SET name=?, unit=?, units_per_box=?, current_stock=?, min_alert_level=?,
                         cost_per_unit=?, cost_per_box=?, selling_price=?, updated_at=NOW() WHERE id=?',
                [$name, $unit, $unitsPerBox, $stock, $minAlert, $costPerUnit, $costPerBox, $sellingPrice, $id]
            );
            $msg = "Inventory item updated.";
        }

    } elseif ($act === 'purchase') {
        $invId = (int)($_POST['inventory_id'] ?? 0);
        $row   = $invId ? $db->fetchOne('SELECT * FROM inventory WHERE id=?', [$invId]) : null;
        if ($row) {
            $isBoxes  = $row['unit'] === 'boxes';
            $notes    = trim($_POST['notes'] ?? '');
            if ($isBoxes) {
                $qtyBoxes  = max(0, (float)($_POST['qty_boxes']   ?? 0));
                $cpb       = $_POST['cost_per_box'] !== '' ? (float)$_POST['cost_per_box']  : (float)($row['cost_per_box'] ?? 0);
                $upb       = max(1, (float)($row['units_per_box'] ?? 1));
                $cpu       = $upb > 0 ? round($cpb / $upb, 4) : 0;
                $qtyUnits  = $qtyBoxes * $upb;
                $totalCost = round($qtyBoxes * $cpb, 2);
                $qtyBoxesLog = $qtyBoxes;
            } else {
                $qtyBoxes  = null;
                $qtyBoxesLog = null;
                $qtyUnits  = max(0, (float)($_POST['qty_units']    ?? 0));
                $cpu       = $_POST['cost_per_unit'] !== '' ? (float)$_POST['cost_per_unit'] : (float)$row['cost_per_unit'];
                $cpb       = null;
                $totalCost = round($qtyUnits * $cpu, 2);
            }
            $stockAdd = $isBoxes ? $qtyBoxes : $qtyUnits;
            if ($stockAdd > 0) {
                $db->beginTransaction();
                try {
                    $newStock = (float)$row['current_stock'] + $stockAdd;
                    $db->query('UPDATE inventory SET current_stock=?, updated_at=NOW() WHERE id=?', [$newStock, $invId]);
                    // Update stored cost if a new cost was explicitly provided
                    if ($isBoxes && isset($_POST['cost_per_box']) && $_POST['cost_per_box'] !== '') {
                        $db->query('UPDATE inventory SET cost_per_box=?, cost_per_unit=? WHERE id=?', [$cpb, $cpu, $invId]);
                    } elseif (!$isBoxes && isset($_POST['cost_per_unit']) && $_POST['cost_per_unit'] !== '') {
                        $db->query('UPDATE inventory SET cost_per_unit=? WHERE id=?', [$cpu, $invId]);
                    }
                    $db->query(
                        'INSERT INTO purchase_log (inventory_id, qty_boxes, qty_units, cost_per_box, cost_per_unit, total_cost, notes, user_id)
                         VALUES (?,?,?,?,?,?,?,?)',
                        [$invId, $qtyBoxesLog, $qtyUnits, $cpb, $cpu, $totalCost, $notes ?: 'Purchase', $_SESSION['user_id']]
                    );
                    $db->commit();
                    $msg = "Purchase recorded for \"{$row['name']}\".";
                } catch (\Exception $e) {
                    $db->rollback();
                    $err = 'Failed to record purchase.';
                }
            } else {
                $err = 'Quantity must be greater than zero.';
            }
        } else {
            $err = 'Invalid inventory item.';
        }

    } elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->query('DELETE FROM inventory WHERE id = ?', [$id]);
            $msg = 'Item deleted.';
        } catch (\Exception $e) {
            $err = 'Cannot delete: item is used in recipes.';
        }
    }

    header('Location: inventory.php' . ($msg ? '?msg=' . urlencode($msg) : ($err ? '?err=' . urlencode($err) : '')));
    exit;
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);
if (isset($_GET['err'])) $err = htmlspecialchars($_GET['err']);

// ── Fetch data ────────────────────────────────────────────────────────────────
$search  = trim($_GET['q'] ?? '');
$filter  = $_GET['filter'] ?? 'all';
$params  = [];
$where   = 'WHERE 1=1';

if ($search) { $where .= ' AND name LIKE ?'; $params[] = "%$search%"; }
if ($filter === 'low') { $where .= ' AND current_stock <= min_alert_level'; }

$items      = $db->fetchAll("SELECT * FROM inventory $where ORDER BY name", $params);
$lowCount   = $db->fetchScalar('SELECT COUNT(*) FROM inventory WHERE current_stock <= min_alert_level');
$editId     = (int)($_GET['edit'] ?? 0);
$editItem   = $editId ? $db->fetchOne('SELECT * FROM inventory WHERE id = ?', [$editId]) : null;
$categories = $db->fetchAll('SELECT id, name FROM categories WHERE is_active=1 ORDER BY name');
$allItems   = $db->fetchAll('SELECT id, name, unit, current_stock, units_per_box, cost_per_unit, cost_per_box FROM inventory ORDER BY name');

$activePage = 'inventory';
$pageTitle  = 'Inventory — ' . RESTAURANT_NAME;
include __DIR__ . '/includes/header.php';
?>

<!-- TOP BAR -->
<header class="bg-white border-b border-slate-200 px-6 py-3.5 flex items-center justify-between flex-shrink-0">
    <div>
        <h1 class="font-bold text-lg text-slate-800">Inventory Management</h1>
        <p class="text-xs text-slate-400"><?= count($items) ?> items · <span class="text-amber-600 font-medium"><?= $lowCount ?> low stock</span></p>
    </div>
    <div class="flex gap-2">
        <button onclick="openPurchaseModal()"
                class="inline-flex items-center gap-2 text-slate-700 text-sm font-bold px-4 py-2 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 transition">
            <i class="fa-solid fa-cart-plus"></i> Record Purchase
        </button>
        <button onclick="document.getElementById('add-modal').classList.remove('hidden')"
                class="inline-flex items-center gap-2 text-black text-sm font-black px-4 py-2 rounded-xl transition"
                style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
            <i class="fa-solid fa-plus"></i> Add Item
        </button>
    </div>
</header>

<!-- MAIN -->
<div class="flex-1 overflow-y-auto p-5 space-y-4">

    <?php if ($msg): ?>
    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm rounded-xl px-4 py-3 flex items-center gap-2">
        <i class="fa-solid fa-check-circle"></i> <?= $msg ?>
    </div>
    <?php endif; ?>
    <?php if ($err): ?>
    <div class="bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded-xl px-4 py-3 flex items-center gap-2">
        <i class="fa-solid fa-triangle-exclamation"></i> <?= $err ?>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="flex gap-3 items-center">
        <form method="GET" class="flex-1 flex gap-2">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search inventory…"
                   class="flex-1 bg-white border border-slate-200 rounded-xl px-4 py-2 text-sm outline-none">
            <select name="filter" onchange="this.form.submit()"
                    class="bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                <option value="all" <?= $filter==='all'?'selected':'' ?>>All Items</option>
                <option value="low" <?= $filter==='low'?'selected':'' ?>>Low Stock Only</option>
            </select>
            <button type="submit" class="bg-slate-800 text-white text-sm px-4 py-2 rounded-xl hover:bg-slate-700 transition">Search</button>
            <?php if ($search || $filter !== 'all'): ?>
            <a href="inventory.php" class="text-slate-500 text-sm px-3 py-2 hover:text-slate-700">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Inventory Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Item</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Unit</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Stock</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Min Alert</th>
                    <th class="px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Level</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Cost/Unit</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Sell/Unit</th>
                    <th class="px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if (empty($items)): ?>
                <tr><td colspan="8" class="text-center py-10 text-slate-400 text-sm">No inventory items found.</td></tr>
                <?php else: ?>
                <?php foreach ($items as $item):
                    $pct   = $item['min_alert_level'] > 0 ? min(100, round(($item['current_stock'] / $item['min_alert_level']) * 100)) : 100;
                    $isLow = $item['current_stock'] <= $item['min_alert_level'];
                    $isOut = $item['current_stock'] == 0;
                    $barClr = $isOut ? 'bg-rose-500' : ($isLow ? 'bg-amber-400' : 'bg-emerald-400');
                ?>
                <tr class="hover:bg-slate-50 transition <?= $isLow ? 'bg-amber-50/30' : '' ?>">
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-2">
                            <?php if ($isOut): ?>
                                <i class="fa-solid fa-circle-xmark text-rose-500 text-xs"></i>
                            <?php elseif ($isLow): ?>
                                <i class="fa-solid fa-triangle-exclamation text-amber-500 text-xs"></i>
                            <?php else: ?>
                                <i class="fa-solid fa-circle-check text-emerald-500 text-xs"></i>
                            <?php endif; ?>
                            <div>
                                <span class="font-medium text-slate-800"><?= htmlspecialchars($item['name']) ?></span>
                                <?php if ($item['unit'] === 'boxes' && $item['units_per_box']): ?>
                                <p class="text-xs text-slate-400"><?= $item['units_per_box'] ?> units/box</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-slate-500"><?= htmlspecialchars($item['unit']) ?></td>
                    <td class="px-4 py-3 text-right font-bold <?= $isLow ? 'text-amber-600' : 'text-slate-700' ?>">
                        <?= number_format($item['current_stock'], 2) ?>
                        <?php if ($item['unit'] === 'boxes' && $item['units_per_box']): ?>
                        <p class="text-xs text-slate-400 font-normal"><?= number_format($item['current_stock'] * $item['units_per_box'], 0) ?> units</p>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right text-slate-500"><?= number_format($item['min_alert_level'], 2) ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 bg-slate-100 rounded-full h-2 w-20">
                                <div class="<?= $barClr ?> h-2 rounded-full" style="width:<?= $pct ?>%"></div>
                            </div>
                            <span class="text-xs text-slate-400 w-8"><?= $pct ?>%</span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-right text-slate-500">
                        <?= CURRENCY_SYMBOL ?><?= number_format($item['cost_per_unit'], 4) ?>
                        <?php if ($item['cost_per_box']): ?>
                        <p class="text-xs text-slate-400"><?= CURRENCY_SYMBOL ?><?= number_format($item['cost_per_box'], 2) ?>/box</p>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <?php if ($item['selling_price']): ?>
                        <span class="font-semibold" style="color:#436800;"><?= CURRENCY_SYMBOL ?><?= number_format($item['selling_price'], 2) ?></span>
                        <?php else: ?>
                        <span class="text-slate-300">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1">
                            <a href="?edit=<?= $item['id'] ?>"
                               class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium transition">
                                Edit
                            </a>
                            <form method="POST" onsubmit="return confirm('Delete this item?')" class="inline">
                                <input type="hidden" name="act" value="delete">
                                <input type="hidden" name="id"  value="<?= $item['id'] ?>">
                                <button type="submit" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-lg text-xs font-medium transition">Del</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ADD MODAL -->
<div id="add-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-6 py-4 border-b sticky top-0 bg-white">
            <h2 class="font-bold text-slate-800">Add Inventory Item</h2>
            <button onclick="document.getElementById('add-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4" id="add-form">
            <input type="hidden" name="act" value="add">
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Item Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Chicken Breast"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
                <div class="col-span-2">
                    <label class="flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" id="add-create-menu" onchange="toggleAddMenuSection()"
                               class="w-4 h-4 rounded accent-green-600">
                        <span class="text-sm font-medium text-slate-700">Also add this item to the menu</span>
                    </label>
                </div>
                <div id="add-menu-section" class="col-span-2 hidden space-y-3 bg-slate-50 border border-slate-200 rounded-xl p-4">
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-wide">Menu Item Details</p>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Category *</label>
                        <select name="category_id" id="add-cat-select" class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none bg-white">
                            <option value="">— Select category —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <p class="text-xs text-slate-400">The menu item will use the item name and selling price per unit above.</p>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Unit *</label>
                    <select name="unit" id="add-unit" onchange="toggleBoxFields('add')"
                            class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                        <option value="pcs">pcs</option>
                        <option value="kg">kg</option>
                        <option value="liters">liters</option>
                        <option value="grams">grams</option>
                        <option value="ml">ml</option>
                        <option value="boxes">boxes</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Current Stock</label>
                    <input type="number" name="current_stock" step="0.001" min="0" value="0"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Min Alert Level</label>
                    <input type="number" name="min_alert_level" step="0.001" min="0" value="5"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>

                <!-- Normal cost field (hidden when boxes) -->
                <div id="add-cost-normal">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Cost / Unit (<?= CURRENCY_SYMBOL ?>)</label>
                    <input type="number" name="cost_per_unit" id="add-cpu" step="0.0001" min="0" value="0"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>

                <!-- Selling price -->
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Selling Price / Unit (<?= CURRENCY_SYMBOL ?>)</label>
                    <input type="number" name="selling_price" step="0.01" min="0" placeholder="0.00"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
            </div>

            <!-- Box-specific fields -->
            <div id="add-box-section" class="hidden space-y-3 bg-amber-50 border border-amber-200 rounded-xl p-4">
                <p class="text-xs font-bold text-amber-700 uppercase tracking-wide flex items-center gap-1">
                    <i class="fa-solid fa-box"></i> Box Details
                </p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Units per Box *</label>
                        <input type="number" name="units_per_box" id="add-upb" min="1" placeholder="e.g. 12"
                               oninput="calcUnitCost('add')"
                               class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Cost per Box (<?= CURRENCY_SYMBOL ?>) *</label>
                        <input type="number" name="cost_per_box" id="add-cpb" step="0.01" min="0" placeholder="0.00"
                               oninput="calcUnitCost('add')"
                               class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Cost per Unit (auto-calculated)</label>
                        <input type="text" id="add-cpu-display" readonly placeholder="—"
                               class="w-full border border-slate-100 rounded-xl px-3 py-2 text-sm bg-slate-50 text-slate-500 cursor-not-allowed">
                    </div>
                </div>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('add-modal').classList.add('hidden')"
                        class="flex-1 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-sm font-medium hover:bg-slate-50 transition">Cancel</button>
                <button type="submit"
                        class="flex-1 py-2.5 rounded-xl text-black text-sm font-black transition"
                        style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">Add Item</button>
            </div>
        </form>
    </div>
</div>

<!-- PURCHASE MODAL -->
<div id="purchase-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[92vh] overflow-y-auto">
        <div class="flex items-center justify-between px-6 py-4 border-b sticky top-0 bg-white z-10">
            <h2 class="font-bold text-slate-800">Record Purchase</h2>
            <button onclick="closePurchaseModal()" class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
        </div>

        <!-- Step 1: Search & select item -->
        <div id="pur-step-search" class="px-6 py-5 space-y-3">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Search inventory item</label>
            <input type="text" id="pur-search" placeholder="Type item name…"
                   oninput="filterPurchaseItems(this.value)"
                   class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-sm outline-none">
            <div id="pur-results" class="space-y-1 max-h-60 overflow-y-auto"></div>
        </div>

        <!-- Step 2: Enter purchase details (hidden until item selected) -->
        <div id="pur-step-details" class="hidden">
            <!-- Selected item banner -->
            <div class="mx-6 mb-1 mt-0 px-4 py-3 rounded-xl flex items-center justify-between" style="background:#f2fce0;">
                <div>
                    <p class="text-sm font-bold" style="color:#436800;" id="pur-sel-name">—</p>
                    <p class="text-xs text-slate-500 mt-0.5">Current stock: <span id="pur-sel-stock"></span></p>
                </div>
                <button onclick="resetPurchaseModal()" class="text-xs text-slate-400 hover:text-slate-600 underline">Change</button>
            </div>

            <form method="POST" id="purchase-form" class="px-6 pb-5 pt-4 space-y-4">
                <input type="hidden" name="act" value="purchase">
                <input type="hidden" name="inventory_id" id="pur-inv-id">

                <!-- Box item fields -->
                <div id="pur-box-fields" class="hidden space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-1">Boxes purchased *</label>
                            <input type="number" name="qty_boxes" id="pur-qty-boxes" step="0.001" min="0.001" placeholder="e.g. 3"
                                   oninput="calcPurchaseTotal()"
                                   class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-1">Cost per box (<?= CURRENCY_SYMBOL ?>)</label>
                            <input type="number" name="cost_per_box" id="pur-cpb" step="0.01" min="0" placeholder="leave blank = keep current"
                                   oninput="calcPurchaseTotal()"
                                   class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-1">Units per box</label>
                            <input type="text" id="pur-upb-display" readonly
                                   class="w-full border border-slate-100 rounded-xl px-3 py-2 text-sm bg-slate-50 text-slate-500 cursor-not-allowed">
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-1">Cost per unit (auto)</label>
                            <input type="text" id="pur-cpu-display" readonly placeholder="—"
                                   class="w-full border border-slate-100 rounded-xl px-3 py-2 text-sm bg-slate-50 text-slate-500 cursor-not-allowed">
                        </div>
                    </div>
                </div>

                <!-- Non-box item fields -->
                <div id="pur-unit-fields" class="hidden grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Quantity *</label>
                        <input type="number" name="qty_units" id="pur-qty-units" step="0.001" min="0.001" placeholder="e.g. 10"
                               oninput="calcPurchaseTotal()"
                               class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Cost per unit (<?= CURRENCY_SYMBOL ?>)</label>
                        <input type="number" name="cost_per_unit" id="pur-cpu" step="0.0001" min="0" placeholder="leave blank = keep current"
                               oninput="calcPurchaseTotal()"
                               class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                    </div>
                </div>

                <!-- Total cost display -->
                <div class="flex items-center justify-between bg-slate-50 rounded-xl px-4 py-3">
                    <span class="text-sm font-semibold text-slate-600">Total Cost</span>
                    <span class="text-lg font-black text-slate-800"><?= CURRENCY_SYMBOL ?><span id="pur-total">0.00</span></span>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Notes (optional)</label>
                    <input type="text" name="notes" placeholder="e.g. Supplier: ABC, Invoice #123"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>

                <div class="flex gap-2 pt-1">
                    <button type="button" onclick="closePurchaseModal()"
                            class="flex-1 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-sm font-medium hover:bg-slate-50 transition">Cancel</button>
                    <button type="submit" class="flex-1 py-2.5 text-black rounded-xl text-sm font-black transition"
                            style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
                        <i class="fa-solid fa-cart-plus mr-1"></i> Record Purchase
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT MODAL -->
<?php if ($editItem): ?>
<div id="edit-modal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-6 py-4 border-b sticky top-0 bg-white">
            <h2 class="font-bold text-slate-800">Edit Item</h2>
            <a href="inventory.php" class="text-slate-400 hover:text-slate-600 text-xl">&times;</a>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4">
            <input type="hidden" name="act" value="edit">
            <input type="hidden" name="id"  value="<?= $editItem['id'] ?>">
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Item Name *</label>
                    <input type="text" name="name" required value="<?= htmlspecialchars($editItem['name']) ?>"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Unit *</label>
                    <select name="unit" id="edit-unit" onchange="toggleBoxFields('edit')"
                            class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                        <?php foreach (['pcs','kg','liters','grams','ml','boxes'] as $u): ?>
                        <option value="<?= $u ?>" <?= $editItem['unit']===$u?'selected':'' ?>><?= $u ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Current Stock</label>
                    <input type="number" name="current_stock" step="0.001" min="0" value="<?= $editItem['current_stock'] ?>"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Min Alert Level</label>
                    <input type="number" name="min_alert_level" step="0.001" min="0" value="<?= $editItem['min_alert_level'] ?>"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
                <div id="edit-cost-normal" <?= $editItem['unit']==='boxes'?'style="display:none"':'' ?>>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Cost / Unit (<?= CURRENCY_SYMBOL ?>)</label>
                    <input type="number" name="cost_per_unit" id="edit-cpu" step="0.0001" min="0" value="<?= $editItem['cost_per_unit'] ?>"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Selling Price / Unit (<?= CURRENCY_SYMBOL ?>)</label>
                    <input type="number" name="selling_price" step="0.01" min="0" placeholder="0.00"
                           value="<?= $editItem['selling_price'] ?? '' ?>"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
            </div>

            <!-- Box fields for edit -->
            <div id="edit-box-section" class="<?= $editItem['unit']==='boxes' ? '' : 'hidden' ?> space-y-3 bg-amber-50 border border-amber-200 rounded-xl p-4">
                <p class="text-xs font-bold text-amber-700 uppercase tracking-wide flex items-center gap-1">
                    <i class="fa-solid fa-box"></i> Box Details
                </p>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Units per Box</label>
                        <input type="number" name="units_per_box" id="edit-upb" min="1" placeholder="e.g. 12"
                               value="<?= $editItem['units_per_box'] ?? '' ?>"
                               oninput="calcUnitCost('edit')"
                               class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Cost per Box (<?= CURRENCY_SYMBOL ?>)</label>
                        <input type="number" name="cost_per_box" id="edit-cpb" step="0.01" min="0" placeholder="0.00"
                               value="<?= $editItem['cost_per_box'] ?? '' ?>"
                               oninput="calcUnitCost('edit')"
                               class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-semibold text-slate-500 mb-1">Cost per Unit (auto-calculated)</label>
                        <input type="text" id="edit-cpu-display" readonly
                               value="<?= $editItem['units_per_box'] && $editItem['cost_per_box'] ? number_format($editItem['cost_per_box']/$editItem['units_per_box'],4) : '' ?>"
                               class="w-full border border-slate-100 rounded-xl px-3 py-2 text-sm bg-slate-50 text-slate-500 cursor-not-allowed">
                    </div>
                </div>
            </div>

            <div class="flex gap-2 pt-2">
                <a href="inventory.php" class="flex-1 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-sm font-medium hover:bg-slate-50 transition text-center">Cancel</a>
                <button type="submit" class="flex-1 py-2.5 rounded-xl text-black text-sm font-black transition"
                        style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<script>document.addEventListener('DOMContentLoaded', () => toggleBoxFields('edit'));</script>
<?php endif; ?>

<script>
// ── Add / Edit box fields ─────────────────────────────────────────────────────
function toggleBoxFields(prefix) {
    const unit     = document.getElementById(prefix + '-unit').value;
    const boxSec   = document.getElementById(prefix + '-box-section');
    const costNorm = document.getElementById(prefix + '-cost-normal');
    const isBoxes  = unit === 'boxes';
    boxSec.classList.toggle('hidden', !isBoxes);
    if (costNorm) costNorm.style.display = isBoxes ? 'none' : '';
}

function calcUnitCost(prefix) {
    const upb  = parseFloat(document.getElementById(prefix + '-upb').value) || 0;
    const cpb  = parseFloat(document.getElementById(prefix + '-cpb').value) || 0;
    const disp = document.getElementById(prefix + '-cpu-display');
    disp.value = (upb > 0 && cpb > 0) ? '<?= CURRENCY_SYMBOL ?>' + (cpb / upb).toFixed(4) : '';
}

// ── Purchase Modal ────────────────────────────────────────────────────────────
const INV_ITEMS = <?= json_encode(array_values(array_map(fn($i) => [
    'id'            => (int)$i['id'],
    'name'          => $i['name'],
    'unit'          => $i['unit'],
    'current_stock' => (float)$i['current_stock'],
    'units_per_box' => $i['units_per_box'] !== null ? (float)$i['units_per_box'] : null,
    'cost_per_unit' => (float)$i['cost_per_unit'],
    'cost_per_box'  => $i['cost_per_box'] !== null ? (float)$i['cost_per_box'] : null,
], $allItems))) ?>;

let _purSelected = null;

function closePurchaseModal() {
    document.getElementById('purchase-modal').classList.add('hidden');
    resetPurchaseModal();
}

function resetPurchaseModal() {
    _purSelected = null;
    document.getElementById('pur-search').value = '';
    document.getElementById('pur-results').innerHTML = '';
    document.getElementById('pur-step-search').classList.remove('hidden');
    document.getElementById('pur-step-details').classList.add('hidden');
    document.getElementById('pur-box-fields').classList.add('hidden');
    document.getElementById('pur-unit-fields').classList.add('hidden');
    document.getElementById('pur-total').textContent = '0.00';
}

function filterPurchaseItems(q) {
    const res = document.getElementById('pur-results');
    const matches = q.trim().length === 0
        ? INV_ITEMS.slice(0, 8)
        : INV_ITEMS.filter(i => i.name.toLowerCase().includes(q.toLowerCase())).slice(0, 12);

    res.innerHTML = matches.map(i => `
        <button type="button" onclick="selectPurchaseItem(${i.id})"
            class="w-full text-left px-3 py-2.5 rounded-xl hover:bg-slate-50 border border-transparent hover:border-slate-200 transition flex items-center justify-between group">
            <div>
                <p class="text-sm font-medium text-slate-800">${i.name}</p>
                <p class="text-xs text-slate-400">${i.unit}${i.units_per_box ? ' · ' + i.units_per_box + ' units/box' : ''}</p>
            </div>
            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-slate-100 text-slate-500 group-hover:bg-slate-200">
                ${i.current_stock.toFixed(2)} ${i.unit}
            </span>
        </button>`).join('');

    if (matches.length === 0 && q.trim()) {
        res.innerHTML = '<p class="text-sm text-slate-400 px-3 py-2">No items match.</p>';
    }
}

function selectPurchaseItem(id) {
    _purSelected = INV_ITEMS.find(i => i.id === id);
    if (!_purSelected) return;

    document.getElementById('pur-inv-id').value    = _purSelected.id;
    document.getElementById('pur-sel-name').textContent  = _purSelected.name;
    document.getElementById('pur-sel-stock').textContent =
        _purSelected.current_stock.toFixed(2) + ' ' + _purSelected.unit +
        (_purSelected.units_per_box ? ' (' + (_purSelected.current_stock * _purSelected.units_per_box).toFixed(0) + ' units)' : '');

    document.getElementById('pur-step-search').classList.add('hidden');
    document.getElementById('pur-step-details').classList.remove('hidden');

    const isBoxes = _purSelected.unit === 'boxes';
    document.getElementById('pur-box-fields').classList.toggle('hidden', !isBoxes);
    document.getElementById('pur-unit-fields').classList.toggle('hidden', isBoxes);

    if (isBoxes) {
        document.getElementById('pur-upb-display').value = _purSelected.units_per_box ?? '—';
        document.getElementById('pur-cpb').value   = _purSelected.cost_per_box  ?? '';
        document.getElementById('pur-qty-boxes').value = '';
    } else {
        document.getElementById('pur-cpu').value      = _purSelected.cost_per_unit ?? '';
        document.getElementById('pur-qty-units').value = '';
    }
    calcPurchaseTotal();
}

function calcPurchaseTotal() {
    if (!_purSelected) return;
    let total = 0;
    if (_purSelected.unit === 'boxes') {
        const qty = parseFloat(document.getElementById('pur-qty-boxes').value) || 0;
        const cpb = parseFloat(document.getElementById('pur-cpb').value) || _purSelected.cost_per_box || 0;
        const upb = _purSelected.units_per_box || 1;
        total = qty * cpb;
        document.getElementById('pur-cpu-display').value = cpb > 0 ? '<?= CURRENCY_SYMBOL ?>' + (cpb / upb).toFixed(4) : '';
    } else {
        const qty = parseFloat(document.getElementById('pur-qty-units').value) || 0;
        const cpu = parseFloat(document.getElementById('pur-cpu').value) || _purSelected.cost_per_unit || 0;
        total = qty * cpu;
    }
    document.getElementById('pur-total').textContent = total.toFixed(2);
}

function openPurchaseModal() {
    document.getElementById('purchase-modal').classList.remove('hidden');
    resetPurchaseModal();
    setTimeout(() => {
        filterPurchaseItems('');
        document.getElementById('pur-search').focus();
    }, 50);
}

function toggleAddMenuSection() {
    const checked = document.getElementById('add-create-menu').checked;
    document.getElementById('add-menu-section').classList.toggle('hidden', !checked);
    if (!checked) document.getElementById('add-cat-select').value = '';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
