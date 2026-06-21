<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$db  = Database::getInstance();
$msg = '';
$err = '';

// ── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';

    if ($act === 'add') {
        $number   = strtoupper(trim($_POST['table_number'] ?? ''));
        $section  = trim($_POST['section'] ?? 'Main Hall');
        $capacity = max(1, (int)($_POST['capacity'] ?? 4));

        if (!$number) {
            $err = 'Table number is required.';
        } else {
            $exists = $db->fetchOne('SELECT id FROM restaurant_tables WHERE table_number = ?', [$number]);
            if ($exists) {
                $err = "Table \"$number\" already exists.";
            } else {
                $db->query(
                    "INSERT INTO restaurant_tables (table_number, section, capacity, status) VALUES (?,?,'open',?)",
                    [$number, $section, $capacity]
                );
                $msg = "Table \"$number\" added to $section.";
            }
        }

    } elseif ($act === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $number   = strtoupper(trim($_POST['table_number'] ?? ''));
        $section  = trim($_POST['section'] ?? 'Main Hall');
        $capacity = max(1, (int)($_POST['capacity'] ?? 4));

        if (!$number) {
            $err = 'Table number is required.';
        } else {
            $dupe = $db->fetchOne('SELECT id FROM restaurant_tables WHERE table_number = ? AND id != ?', [$number, $id]);
            if ($dupe) {
                $err = "Table number \"$number\" is already used.";
            } else {
                $db->query(
                    'UPDATE restaurant_tables SET table_number=?, section=?, capacity=?, updated_at=NOW() WHERE id=?',
                    [$number, $section, $capacity, $id]
                );
                $msg = "Table \"$number\" updated.";
            }
        }

    } elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $table = $db->fetchOne('SELECT * FROM restaurant_tables WHERE id = ?', [$id]);
        if (!$table) {
            $err = 'Table not found.';
        } elseif ($table['status'] === 'occupied') {
            $err = "Cannot delete Table {$table['table_number']} — it is currently occupied.";
        } else {
            // Check for pending orders
            $activeOrder = $db->fetchScalar(
                "SELECT COUNT(*) FROM orders WHERE table_id = ? AND status NOT IN ('paid','cancelled')",
                [$id]
            );
            if ($activeOrder > 0) {
                $err = "Cannot delete Table {$table['table_number']} — it has an active order.";
            } else {
                $db->query('DELETE FROM restaurant_tables WHERE id = ?', [$id]);
                $msg = "Table \"{$table['table_number']}\" removed.";
            }
        }
    }

    header('Location: tables.php' . ($msg ? '?msg=' . urlencode($msg) : ($err ? '?err=' . urlencode($err) : '')));
    exit;
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);
if (isset($_GET['err'])) $err = htmlspecialchars($_GET['err']);

$tables  = $db->fetchAll(
    "SELECT t.*,
            (SELECT COUNT(*) FROM orders o WHERE o.table_id = t.id AND o.status NOT IN ('paid','cancelled')) AS active_orders
       FROM restaurant_tables t
      ORDER BY t.section, t.table_number"
);
$editId   = (int)($_GET['edit'] ?? 0);
$editTable = $editId ? $db->fetchOne('SELECT * FROM restaurant_tables WHERE id = ?', [$editId]) : null;

// Group by section
$sections = [];
foreach ($tables as $t) {
    $sections[$t['section']][] = $t;
}

// Distinct existing sections for datalist
$sectionNames = array_keys($sections);

$activePage = 'tables';
$pageTitle  = 'Tables — ' . RESTAURANT_NAME;
include __DIR__ . '/includes/header.php';

$statusStyle = [
    'open'     => 'bg-emerald-100 text-emerald-700',
    'occupied' => 'bg-rose-100 text-rose-600',
    'reserved' => 'bg-amber-100 text-amber-700',
    'cleaning' => 'bg-slate-100 text-slate-500',
];
?>

<!-- TOP BAR -->
<header class="bg-white border-b border-slate-200 px-6 py-3.5 flex items-center justify-between flex-shrink-0">
    <div>
        <h1 class="font-bold text-lg text-slate-800">Table Management</h1>
        <p class="text-xs text-slate-400"><?= count($tables) ?> tables · <?= count($sections) ?> sections</p>
    </div>
    <button onclick="document.getElementById('add-modal').classList.remove('hidden')"
            class="inline-flex items-center gap-2 text-sm font-black px-4 py-2 rounded-xl transition text-black"
            style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
        <i class="fa-solid fa-plus"></i> Add Table
    </button>
</header>

<!-- MAIN -->
<div class="flex-1 overflow-y-auto p-5 space-y-5">

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

    <?php if (empty($tables)): ?>
    <div class="bg-white rounded-2xl p-12 text-center border border-slate-100 shadow-sm">
        <i class="fa-solid fa-chair text-4xl text-slate-200 mb-4"></i>
        <p class="text-slate-400 font-medium">No tables yet.</p>
        <p class="text-slate-300 text-sm mt-1">Click "Add Table" to get started.</p>
    </div>
    <?php else: ?>

    <?php foreach ($sections as $sectionName => $sectionTables): ?>
    <div>
        <h2 class="text-xs font-bold text-slate-400 uppercase tracking-widest mb-3 px-1">
            <i class="fa-solid fa-location-dot mr-1"></i><?= htmlspecialchars($sectionName) ?>
            <span class="font-normal text-slate-300">(<?= count($sectionTables) ?> tables)</span>
        </h2>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-3">
            <?php foreach ($sectionTables as $t):
                $canDelete = $t['status'] !== 'occupied' && $t['active_orders'] == 0;
            ?>
            <div class="bg-white rounded-2xl border border-slate-100 shadow-sm overflow-hidden flex flex-col">
                <!-- Table header -->
                <div class="p-4 flex-1">
                    <div class="flex items-start justify-between mb-2">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center font-black text-sm text-black"
                             style="background:#76B900;"><?= htmlspecialchars($t['table_number']) ?></div>
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $statusStyle[$t['status']] ?? 'bg-slate-100 text-slate-500' ?>">
                            <?= ucfirst($t['status']) ?>
                        </span>
                    </div>
                    <p class="text-xs text-slate-500 mt-2">
                        <i class="fa-solid fa-users text-slate-300 mr-1"></i><?= $t['capacity'] ?> seats
                    </p>
                </div>
                <!-- Actions -->
                <div class="border-t border-slate-100 px-3 py-2 flex gap-1.5">
                    <a href="?edit=<?= $t['id'] ?>"
                       class="flex-1 text-center py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium transition">
                        Edit
                    </a>
                    <form method="POST" class="flex-1"
                          onsubmit="return confirm('Remove table <?= htmlspecialchars(addslashes($t['table_number'])) ?>? This cannot be undone.')">
                        <input type="hidden" name="act" value="delete">
                        <input type="hidden" name="id"  value="<?= $t['id'] ?>">
                        <button type="submit"
                                <?= !$canDelete ? 'disabled title="Table is occupied or has an active order"' : '' ?>
                                class="w-full py-1.5 rounded-lg text-xs font-medium transition
                                    <?= $canDelete ? 'bg-rose-50 hover:bg-rose-100 text-rose-600' : 'bg-slate-50 text-slate-300 cursor-not-allowed' ?>">
                            Remove
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ADD TABLE MODAL -->
<div id="add-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h2 class="font-bold text-slate-800">Add Table</h2>
            <button onclick="document.getElementById('add-modal').classList.add('hidden')"
                    class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4">
            <input type="hidden" name="act" value="add">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Table Number / Label *</label>
                <input type="text" name="table_number" required placeholder="e.g. T11, B5, VIP1"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400"
                       style="text-transform:uppercase;">
                <p class="text-xs text-slate-400 mt-1">Must be unique. Letters and numbers only.</p>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Section *</label>
                <input type="text" name="section" list="section-list" required placeholder="e.g. Main Hall"
                       value="Main Hall"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
                <datalist id="section-list">
                    <?php foreach ($sectionNames as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Seating Capacity *</label>
                <input type="number" name="capacity" min="1" max="30" value="4" required
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div class="flex gap-2 pt-1">
                <button type="button" onclick="document.getElementById('add-modal').classList.add('hidden')"
                        class="flex-1 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-sm font-medium hover:bg-slate-50 transition">Cancel</button>
                <button type="submit"
                        class="flex-1 py-2.5 rounded-xl text-black text-sm font-black transition"
                        style="background:#76B900;">Add Table</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT TABLE MODAL (auto-opens if ?edit=X) -->
<?php if ($editTable): ?>
<div id="edit-modal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h2 class="font-bold text-slate-800">Edit Table <?= htmlspecialchars($editTable['table_number']) ?></h2>
            <a href="tables.php" class="text-slate-400 hover:text-slate-600 text-xl">&times;</a>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4">
            <input type="hidden" name="act" value="edit">
            <input type="hidden" name="id"  value="<?= $editTable['id'] ?>">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Table Number / Label *</label>
                <input type="text" name="table_number" required
                       value="<?= htmlspecialchars($editTable['table_number']) ?>"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400"
                       style="text-transform:uppercase;">
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Section *</label>
                <input type="text" name="section" list="section-list-edit" required
                       value="<?= htmlspecialchars($editTable['section']) ?>"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
                <datalist id="section-list-edit">
                    <?php foreach ($sectionNames as $s): ?>
                    <option value="<?= htmlspecialchars($s) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-1">Seating Capacity *</label>
                <input type="number" name="capacity" min="1" max="30" required
                       value="<?= $editTable['capacity'] ?>"
                       class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none focus:border-brand-400">
            </div>
            <div class="flex gap-2 pt-1">
                <a href="tables.php"
                   class="flex-1 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-sm font-medium hover:bg-slate-50 transition text-center">Cancel</a>
                <button type="submit"
                        class="flex-1 py-2.5 rounded-xl text-black text-sm font-black transition"
                        style="background:#76B900;">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
