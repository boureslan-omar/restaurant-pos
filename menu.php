<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$db  = Database::getInstance();
$msg = '';
$err = '';

// ── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'add_item') {
        $catId = (int)$_POST['category_id'];
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $price = (float)$_POST['price'];
        $avail = isset($_POST['is_available']) ? 1 : 0;
        $track = isset($_POST['track_stock'])  ? 1 : 0;
        $stock = ($track && $_POST['stock_count'] !== '') ? (int)$_POST['stock_count'] : null;

        if (!$name || !$catId || $price <= 0) { $err = 'Name, category, and a valid price are required.'; }
        else {
            $db->query(
                'INSERT INTO menu_items (category_id, name, description, price, is_available, track_stock, stock_count) VALUES (?,?,?,?,?,?,?)',
                [$catId, $name, $desc, $price, $avail, $track, $stock]
            );
            $msg = "\"$name\" added to menu.";
        }

    } elseif ($act === 'edit_item') {
        $id    = (int)$_POST['id'];
        $catId = (int)$_POST['category_id'];
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $price = (float)$_POST['price'];
        $avail = isset($_POST['is_available']) ? 1 : 0;
        $track = isset($_POST['track_stock'])  ? 1 : 0;
        $stock = ($track && $_POST['stock_count'] !== '') ? (int)$_POST['stock_count'] : null;

        if (!$name || !$catId || $price <= 0) { $err = 'Invalid data.'; }
        else {
            $db->query(
                'UPDATE menu_items SET category_id=?, name=?, description=?, price=?, is_available=?, track_stock=?, stock_count=?, updated_at=NOW() WHERE id=?',
                [$catId, $name, $desc, $price, $avail, $track, $stock, $id]
            );
            $msg = "\"$name\" updated.";
        }

    } elseif ($act === 'toggle_item') {
        $id  = (int)$_POST['id'];
        $cur = (int)$_POST['current'];
        $db->query('UPDATE menu_items SET is_available = ?, updated_at = NOW() WHERE id = ?', [$cur ? 0 : 1, $id]);
        $msg = 'Item availability toggled.';

    } elseif ($act === 'delete_item') {
        $id = (int)$_POST['id'];
        try {
            $db->query('DELETE FROM menu_items WHERE id = ?', [$id]);
            $msg = 'Menu item deleted.';
        } catch (\Exception $e) {
            $err = 'Cannot delete: item may have order history.';
        }

    } elseif ($act === 'add_category') {
        $name  = trim($_POST['cat_name'] ?? '');
        $desc  = trim($_POST['cat_desc'] ?? '');
        $order = (int)($_POST['sort_order'] ?? 0);
        if (!$name) { $err = 'Category name required.'; }
        else {
            $db->query('INSERT INTO categories (name, description, sort_order) VALUES (?,?,?)', [$name, $desc, $order]);
            $msg = "Category \"$name\" created.";
        }

    } elseif ($act === 'toggle_category') {
        $id  = (int)$_POST['id'];
        $cur = (int)$_POST['current'];
        $db->query('UPDATE categories SET is_active = ? WHERE id = ?', [$cur ? 0 : 1, $id]);
        $msg = 'Category status toggled.';
    }

    header('Location: menu.php' . ($msg ? '?msg='.urlencode($msg) : ($err ? '?err='.urlencode($err) : '')));
    exit;
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);
if (isset($_GET['err'])) $err = htmlspecialchars($_GET['err']);

// ── Fetch data ───────────────────────────────────────────────────────────────
$tab        = $_GET['tab'] ?? 'items';   // items | categories
$catFilter  = (int)($_GET['cat'] ?? 0);
$search     = trim($_GET['q'] ?? '');
$editId     = (int)($_GET['edit'] ?? 0);

$categories = $db->fetchAll('SELECT * FROM categories ORDER BY sort_order, name');

$params = [];
$where  = 'WHERE 1=1';
if ($catFilter) { $where .= ' AND m.category_id = ?'; $params[] = $catFilter; }
if ($search)    { $where .= ' AND m.name LIKE ?';     $params[] = "%$search%"; }

$menuItems = $db->fetchAll(
    "SELECT m.*, c.name AS cat_name FROM menu_items m JOIN categories c ON c.id = m.category_id $where ORDER BY c.sort_order, m.name",
    $params
);

$editItem = $editId ? $db->fetchOne('SELECT * FROM menu_items WHERE id = ?', [$editId]) : null;

$activePage = 'menu';
$pageTitle  = 'Menu Management — ' . RESTAURANT_NAME;
include __DIR__ . '/includes/header.php';
?>

<!-- TOP BAR -->
<header class="bg-white border-b border-slate-200 px-6 py-3.5 flex items-center justify-between flex-shrink-0">
    <div>
        <h1 class="font-bold text-lg text-slate-800">Menu Management</h1>
        <p class="text-xs text-slate-400"><?= count($menuItems) ?> items · <?= count($categories) ?> categories</p>
    </div>
    <div class="flex gap-2">
        <button onclick="document.getElementById('add-cat-modal').classList.remove('hidden')"
                class="inline-flex items-center gap-2 text-sm font-semibold px-3 py-2 rounded-xl transition"
                style="border:1px solid #76B900; color:#436800; background:transparent;"
                onmouseover="this.style.background='#f2fce0'" onmouseout="this.style.background='transparent'">
            <i class="fa-solid fa-folder-plus"></i> Category
        </button>
        <button onclick="document.getElementById('add-item-modal').classList.remove('hidden')"
                class="inline-flex items-center gap-2 text-sm font-black px-4 py-2 rounded-xl transition text-black"
                style="background:#76B900;"
                onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
            <i class="fa-solid fa-plus"></i> Menu Item
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

    <!-- Tab bar -->
    <div class="flex border-b border-slate-200 gap-0">
        <a href="?tab=items"      class="px-5 py-2.5 text-sm font-medium border-b-2 transition <?= $tab==='items'      ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700' ?>">Menu Items</a>
        <a href="?tab=categories" class="px-5 py-2.5 text-sm font-medium border-b-2 transition <?= $tab==='categories' ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-700' ?>">Categories</a>
    </div>

    <?php if ($tab === 'items'): ?>
    <!-- ── ITEMS TAB ── -->

    <?php if (empty($categories)): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl px-5 py-4 flex items-start gap-3">
        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
        <div>
            <p class="font-semibold text-amber-800 text-sm">No categories yet</p>
            <p class="text-amber-700 text-sm mt-0.5">You must create at least one category before adding menu items.</p>
            <button onclick="document.getElementById('add-cat-modal').classList.remove('hidden')"
                    class="mt-3 inline-flex items-center gap-2 text-sm font-semibold px-4 py-2 rounded-xl bg-amber-100 hover:bg-amber-200 text-amber-900 transition">
                <i class="fa-solid fa-folder-plus"></i> Add a Category First
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="flex gap-2">
        <form method="GET" class="flex gap-2 flex-1">
            <input type="hidden" name="tab" value="items">
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search items…"
                   class="flex-1 bg-white border border-slate-200 rounded-xl px-4 py-2 text-sm outline-none focus:border-brand-400">
            <select name="cat" onchange="this.form.submit()"
                    class="bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                <option value="">All Categories</option>
                <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $catFilter===$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="bg-slate-800 text-white text-sm px-4 py-2 rounded-xl hover:bg-slate-700 transition">Go</button>
            <?php if ($search || $catFilter): ?>
            <a href="?tab=items" class="text-slate-400 text-sm px-3 py-2 hover:text-slate-600">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Items grid -->
    <?php if (empty($menuItems)): ?>
    <div class="bg-white rounded-2xl p-10 text-center text-slate-400 border border-slate-100 shadow-sm">
        <i class="fa-solid fa-plate-wheat text-3xl mb-3 text-slate-300"></i>
        <p>No menu items found.</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        <?php foreach ($menuItems as $item): ?>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden flex flex-col <?= !$item['is_available'] ? 'opacity-60' : '' ?>">
            <div class="p-4 flex-1">
                <div class="flex items-start justify-between gap-2 mb-1">
                    <h3 class="font-semibold text-slate-800 text-sm leading-tight"><?= htmlspecialchars($item['name']) ?></h3>
                    <span class="font-bold text-brand-600 text-sm flex-shrink-0"><?= CURRENCY_SYMBOL ?><?= number_format($item['price'], 2) ?></span>
                </div>
                <p class="text-xs text-slate-400 mb-2"><?= htmlspecialchars($item['cat_name']) ?></p>
                <?php if ($item['description']): ?>
                <p class="text-xs text-slate-500 line-clamp-2 mb-2"><?= htmlspecialchars($item['description']) ?></p>
                <?php endif; ?>
                <div class="flex flex-wrap gap-1">
                    <span class="text-xs px-2 py-0.5 rounded-full <?= $item['is_available'] ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-600' ?>">
                        <?= $item['is_available'] ? 'Available' : 'Unavailable' ?>
                    </span>
                    <?php if ($item['track_stock']): ?>
                    <span class="text-xs px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">
                        Stock: <?= $item['stock_count'] ?? 'Recipe' ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="border-t border-slate-100 px-4 py-2 flex gap-1 items-center">
                <a href="?tab=items&edit=<?= $item['id'] ?>"
                   class="flex-1 text-center py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium transition">Edit</a>
                <form method="POST" class="inline">
                    <input type="hidden" name="act"     value="toggle_item">
                    <input type="hidden" name="id"      value="<?= $item['id'] ?>">
                    <input type="hidden" name="current" value="<?= $item['is_available'] ?>">
                    <button type="submit"
                            class="px-2.5 py-1.5 <?= $item['is_available'] ? 'bg-amber-50 text-amber-700 hover:bg-amber-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?> rounded-lg text-xs font-medium transition">
                        <?= $item['is_available'] ? 'Disable' : 'Enable' ?>
                    </button>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this item?')" class="inline">
                    <input type="hidden" name="act" value="delete_item">
                    <input type="hidden" name="id"  value="<?= $item['id'] ?>">
                    <button type="submit" class="px-2.5 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-lg text-xs font-medium transition">Del</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- ── CATEGORIES TAB ── -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Name</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Description</th>
                    <th class="text-center px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Order</th>
                    <th class="text-center px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php foreach ($categories as $cat): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3 font-semibold text-slate-800"><?= htmlspecialchars($cat['name']) ?></td>
                    <td class="px-4 py-3 text-slate-500 text-xs"><?= htmlspecialchars($cat['description'] ?? '') ?></td>
                    <td class="px-4 py-3 text-center text-slate-500"><?= $cat['sort_order'] ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $cat['is_active'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>">
                            <?= $cat['is_active'] ? 'Active' : 'Hidden' ?>
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        <form method="POST" class="inline">
                            <input type="hidden" name="act"     value="toggle_category">
                            <input type="hidden" name="id"      value="<?= $cat['id'] ?>">
                            <input type="hidden" name="current" value="<?= $cat['is_active'] ?>">
                            <button type="submit"
                                    class="px-3 py-1 text-xs rounded-lg font-medium transition <?= $cat['is_active'] ? 'bg-slate-100 hover:bg-slate-200 text-slate-600' : 'bg-emerald-50 hover:bg-emerald-100 text-emerald-700' ?>">
                                <?= $cat['is_active'] ? 'Hide' : 'Show' ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ADD ITEM MODAL -->
<div id="add-item-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-6 py-4 border-b sticky top-0 bg-white">
            <h2 class="font-bold text-slate-800">Add Menu Item</h2>
            <button onclick="document.getElementById('add-item-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4">
            <input type="hidden" name="act" value="add_item">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Item Name *</label>
                <input type="text" name="name" required placeholder="e.g. Grilled Chicken"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Category *</label>
                    <select name="category_id" required
                            class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
                        <option value="">Select…</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Price (<?= CURRENCY_SYMBOL ?>) *</label>
                    <input type="number" name="price" step="0.01" min="0.01" required placeholder="0.00"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Description</label>
                <textarea name="description" rows="2" placeholder="Short description…"
                          class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400 resize-none"></textarea>
            </div>
            <div class="flex gap-4">
                <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                    <input type="checkbox" name="is_available" checked class="rounded accent-brand-600">
                    Available
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                    <input type="checkbox" name="track_stock" id="new-track" onchange="document.getElementById('new-stock-wrap').classList.toggle('hidden', !this.checked)" class="rounded accent-brand-600">
                    Track Portions
                </label>
            </div>
            <div id="new-stock-wrap" class="hidden">
                <label class="block text-xs font-semibold text-slate-500 mb-1">Starting Portion Count</label>
                <input type="number" name="stock_count" min="0" placeholder="e.g. 20"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('add-item-modal').classList.add('hidden')"
                        class="flex-1 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-sm font-medium hover:bg-slate-50 transition">Cancel</button>
                <button type="submit"
                        class="flex-1 py-2.5 rounded-xl text-sm font-black text-black transition"
                        style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">Add Item</button>
            </div>
        </form>
    </div>
</div>

<!-- ADD CATEGORY MODAL -->
<div id="add-cat-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h2 class="font-bold text-slate-800">Add Category</h2>
            <button onclick="document.getElementById('add-cat-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4">
            <input type="hidden" name="act" value="add_category">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Category Name *</label>
                <input type="text" name="cat_name" required placeholder="e.g. Desserts"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Description</label>
                <input type="text" name="cat_desc" placeholder="Optional description"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Display Order</label>
                <input type="number" name="sort_order" value="0" min="0"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('add-cat-modal').classList.add('hidden')"
                        class="flex-1 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-sm font-medium hover:bg-slate-50 transition">Cancel</button>
                <button type="submit"
                        class="flex-1 py-2.5 rounded-xl text-sm font-black text-black transition"
                        style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">Create</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT ITEM MODAL (auto-opens if ?edit=X) -->
<?php if ($editItem): ?>
<div id="edit-item-modal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-6 py-4 border-b sticky top-0 bg-white">
            <h2 class="font-bold text-slate-800">Edit Menu Item</h2>
            <a href="?tab=items" class="text-slate-400 hover:text-slate-600 text-xl">&times;</a>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4">
            <input type="hidden" name="act" value="edit_item">
            <input type="hidden" name="id"  value="<?= $editItem['id'] ?>">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Item Name *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($editItem['name']) ?>"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Category *</label>
                    <select name="category_id" required
                            class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $editItem['category_id']==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Price (<?= CURRENCY_SYMBOL ?>)</label>
                    <input type="number" name="price" step="0.01" min="0.01" required value="<?= $editItem['price'] ?>"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Description</label>
                <textarea name="description" rows="2"
                          class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400 resize-none"><?= htmlspecialchars($editItem['description'] ?? '') ?></textarea>
            </div>
            <div class="flex gap-4">
                <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                    <input type="checkbox" name="is_available" <?= $editItem['is_available']?'checked':'' ?> class="rounded accent-brand-600">
                    Available
                </label>
                <label class="flex items-center gap-2 text-sm text-slate-700 cursor-pointer">
                    <input type="checkbox" name="track_stock" <?= $editItem['track_stock']?'checked':'' ?>
                           id="edit-track" onchange="document.getElementById('edit-stock-wrap').classList.toggle('hidden', !this.checked)"
                           class="rounded accent-brand-600">
                    Track Portions
                </label>
            </div>
            <div id="edit-stock-wrap" class="<?= $editItem['track_stock'] ? '' : 'hidden' ?>">
                <label class="block text-xs font-semibold text-slate-500 mb-1">Portion Count</label>
                <input type="number" name="stock_count" min="0" value="<?= $editItem['stock_count'] ?? '' ?>"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div class="flex gap-2 pt-2">
                <a href="?tab=items" class="flex-1 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-sm font-medium hover:bg-slate-50 transition text-center">Cancel</a>
                <button type="submit" class="flex-1 py-2.5 rounded-xl text-sm font-black text-black transition"
                        style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

