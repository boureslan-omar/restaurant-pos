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
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? 'cashier';
        $pass  = $_POST['password'] ?? '';
        $roles = ['admin','cashier','waiter','kitchen'];

        if (!$name || !$email || !$pass) {
            $err = 'Name, email, and password are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Invalid email address.';
        } elseif (!in_array($role, $roles)) {
            $err = 'Invalid role.';
        } else {
            $existing = $db->fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
            if ($existing) {
                $err = 'A user with that email already exists.';
            } else {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $db->query(
                    'INSERT INTO users (name, email, password_hash, role) VALUES (?,?,?,?)',
                    [$name, $email, $hash, $role]
                );
                $msg = "User \"$name\" created.";
            }
        }

    } elseif ($act === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');
        $role  = $_POST['role'] ?? '';
        $pass  = $_POST['password'] ?? '';
        $roles = ['admin','cashier','waiter','kitchen'];

        if (!$name || !$email || !in_array($role, $roles)) {
            $err = 'Invalid data.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Invalid email address.';
        } else {
            $dupe = $db->fetchOne('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $id]);
            if ($dupe) {
                $err = 'That email is already used by another account.';
            } else {
                if ($pass) {
                    $hash = password_hash($pass, PASSWORD_BCRYPT);
                    $db->query(
                        'UPDATE users SET name=?, email=?, role=?, password_hash=?, updated_at=NOW() WHERE id=?',
                        [$name, $email, $role, $hash, $id]
                    );
                } else {
                    $db->query(
                        'UPDATE users SET name=?, email=?, role=?, updated_at=NOW() WHERE id=?',
                        [$name, $email, $role, $id]
                    );
                }
                $msg = "User \"$name\" updated.";
            }
        }

    } elseif ($act === 'toggle') {
        $id  = (int)($_POST['id'] ?? 0);
        $cur = (int)($_POST['current'] ?? 1);
        if ($id === (int)$_SESSION['user_id']) {
            $err = 'You cannot deactivate your own account.';
        } else {
            $db->query('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?', [$cur ? 0 : 1, $id]);
            $msg = 'User status updated.';
        }

    } elseif ($act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)$_SESSION['user_id']) {
            $err = 'You cannot delete your own account.';
        } else {
            $db->query('DELETE FROM users WHERE id = ?', [$id]);
            $msg = 'User deleted.';
        }
    }

    header('Location: users.php' . ($msg ? '?msg=' . urlencode($msg) : ($err ? '?err=' . urlencode($err) : '')));
    exit;
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);
if (isset($_GET['err'])) $err = htmlspecialchars($_GET['err']);

$users  = $db->fetchAll('SELECT * FROM users ORDER BY role, name');
$editId = (int)($_GET['edit'] ?? 0);
$editUser = $editId ? $db->fetchOne('SELECT * FROM users WHERE id = ?', [$editId]) : null;

$activePage = 'users';
$pageTitle  = 'Users — ' . RESTAURANT_NAME;
include __DIR__ . '/includes/header.php';

$roleColors = [
    'admin'   => 'bg-purple-100 text-purple-700',
    'cashier' => 'bg-blue-100 text-blue-700',
    'waiter'  => 'bg-amber-100 text-amber-700',
    'kitchen' => 'bg-orange-100 text-orange-700',
];
?>

<!-- TOP BAR -->
<header class="bg-white border-b border-slate-200 px-6 py-3.5 flex items-center justify-between flex-shrink-0">
    <div>
        <h1 class="font-bold text-lg text-slate-800">User Management</h1>
        <p class="text-xs text-slate-400"><?= count($users) ?> accounts</p>
    </div>
    <button onclick="document.getElementById('add-modal').classList.remove('hidden')"
            class="inline-flex items-center gap-2 text-sm font-semibold px-4 py-2 rounded-xl transition text-black"
            style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
        <i class="fa-solid fa-user-plus"></i> Add User
    </button>
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

    <!-- Users Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Name</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Email</th>
                    <th class="text-center px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Role</th>
                    <th class="text-center px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Status</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Created</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php foreach ($users as $u): $isMe = $u['id'] == $_SESSION['user_id']; ?>
                <tr class="hover:bg-slate-50 transition <?= !$u['is_active'] ? 'opacity-50' : '' ?>">
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-sm text-black flex-shrink-0"
                                 style="background:#76B900;">
                                <?= strtoupper(substr($u['name'], 0, 1)) ?>
                            </div>
                            <div>
                                <p class="font-semibold text-slate-800"><?= htmlspecialchars($u['name']) ?><?= $isMe ? ' <span class="text-xs text-slate-400">(you)</span>' : '' ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-slate-500"><?= htmlspecialchars($u['email']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $roleColors[$u['role']] ?? 'bg-slate-100 text-slate-500' ?>">
                            <?= ucfirst($u['role']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs px-2 py-0.5 rounded-full font-medium <?= $u['is_active'] ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-400' ?>">
                            <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-slate-400 text-xs"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1 justify-end">
                            <a href="?edit=<?= $u['id'] ?>"
                               class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-xs font-medium transition">
                                Edit
                            </a>
                            <?php if (!$isMe): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="act"     value="toggle">
                                <input type="hidden" name="id"      value="<?= $u['id'] ?>">
                                <input type="hidden" name="current" value="<?= $u['is_active'] ?>">
                                <button type="submit"
                                        class="px-2.5 py-1 rounded-lg text-xs font-medium transition <?= $u['is_active'] ? 'bg-amber-50 text-amber-700 hover:bg-amber-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?>">
                                    <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                            <form method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($u['name'])) ?>?')" class="inline">
                                <input type="hidden" name="act" value="delete">
                                <input type="hidden" name="id"  value="<?= $u['id'] ?>">
                                <button type="submit" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-600 rounded-lg text-xs font-medium transition">Del</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Role legend -->
    <div class="flex flex-wrap gap-3 text-xs text-slate-500">
        <span class="font-semibold text-slate-600">Roles:</span>
        <span><span class="inline-block w-2 h-2 rounded-full bg-purple-400 mr-1"></span>Admin — full access</span>
        <span><span class="inline-block w-2 h-2 rounded-full bg-blue-400 mr-1"></span>Cashier — POS, orders, dashboard</span>
        <span><span class="inline-block w-2 h-2 rounded-full bg-amber-400 mr-1"></span>Waiter — POS, orders, dashboard</span>
        <span><span class="inline-block w-2 h-2 rounded-full bg-orange-400 mr-1"></span>Kitchen — dashboard only</span>
    </div>
</div>

<!-- ADD USER MODAL -->
<div id="add-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h2 class="font-bold text-slate-800">Add User</h2>
            <button onclick="document.getElementById('add-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-xl">&times;</button>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4">
            <input type="hidden" name="act" value="add">
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Full Name *</label>
                    <input type="text" name="name" required placeholder="e.g. Jane Smith"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Email *</label>
                    <input type="email" name="email" required placeholder="jane@example.com"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Role *</label>
                    <select name="role" class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                        <option value="cashier">Cashier</option>
                        <option value="waiter">Waiter</option>
                        <option value="kitchen">Kitchen</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Password *</label>
                    <input type="password" name="password" required placeholder="Min 6 chars"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="document.getElementById('add-modal').classList.add('hidden')"
                        class="flex-1 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-sm font-medium hover:bg-slate-50 transition">Cancel</button>
                <button type="submit"
                        class="flex-1 py-2.5 rounded-xl text-black text-sm font-bold transition"
                        style="background:#76B900;">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- EDIT USER MODAL (auto-opens if ?edit=X) -->
<?php if ($editUser): ?>
<div id="edit-modal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md">
        <div class="flex items-center justify-between px-6 py-4 border-b">
            <h2 class="font-bold text-slate-800">Edit User</h2>
            <a href="users.php" class="text-slate-400 hover:text-slate-600 text-xl">&times;</a>
        </div>
        <form method="POST" class="px-6 py-4 space-y-4">
            <input type="hidden" name="act" value="edit">
            <input type="hidden" name="id"  value="<?= $editUser['id'] ?>">
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Full Name *</label>
                    <input type="text" name="name" required value="<?= htmlspecialchars($editUser['name']) ?>"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Email *</label>
                    <input type="email" name="email" required value="<?= htmlspecialchars($editUser['email']) ?>"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">Role *</label>
                    <select name="role" class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                        <?php foreach (['cashier','waiter','kitchen','admin'] as $r): ?>
                        <option value="<?= $r ?>" <?= $editUser['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-500 mb-1">New Password <span class="font-normal text-slate-400">(leave blank to keep)</span></label>
                    <input type="password" name="password" placeholder="New password"
                           class="w-full border border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
            </div>
            <div class="flex gap-2 pt-2">
                <a href="users.php" class="flex-1 py-2.5 border border-slate-200 rounded-xl text-slate-600 text-sm font-medium hover:bg-slate-50 transition text-center">Cancel</a>
                <button type="submit"
                        class="flex-1 py-2.5 rounded-xl text-black text-sm font-bold transition"
                        style="background:#76B900;">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
