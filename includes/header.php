<?php
require_once __DIR__ . '/auth.php';

$activePage = $activePage ?? 'dashboard';
$userName   = $_SESSION['user_name'] ?? 'Staff';
$userRole   = $_SESSION['user_role'] ?? '';
$navItems   = navItemsForRole();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? RESTAURANT_NAME . ' POS') ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script>
        window.tailwind = {
            config: {
                theme: {
                    extend: {
                        colors: {
                            brand: {
                                50:'#f2fce0', 100:'#e0f7b3', 200:'#c6ee6e',
                                300:'#a8df2a', 400:'#8ecf00', 500:'#76B900',
                                600:'#5c9000', 700:'#436800', 800:'#2e4700', 900:'#1a2800',
                            }
                        }
                    }
                }
            }
        };
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <style>
        body { font-family:'Inter', sans-serif; }
        .brand-font { font-family:'Bebas Neue', sans-serif; }

        /* ── Sidebar ──────────────────────────────────────────────── */
        #sidebar {
            width: 220px;
            transition: width .22s cubic-bezier(.4,0,.2,1);
            overflow: hidden;
            flex-shrink: 0;
        }
        #sidebar.collapsed { width: 52px; }

        /* Text elements that vanish on collapse */
        .sb-text {
            transition: opacity .15s, max-width .22s;
            max-width: 160px; opacity: 1; overflow: hidden; white-space: nowrap;
        }
        #sidebar.collapsed .sb-text { opacity: 0; max-width: 0; }

        /* Nav item layout */
        .nav-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 12px; border-radius: 10px;
            transition: background .15s, color .15s;
            color: #555; font-size: 14px; font-weight: 500;
            text-decoration: none; white-space: nowrap;
            position: relative;
        }
        #sidebar.collapsed .nav-item { justify-content: center; padding: 10px; gap: 0; }
        .nav-item.active  { background: rgba(118,185,0,.13); color: #76B900; }
        .nav-item:not(.active):hover { background: rgba(255,255,255,.06); color: #ccc; }

        /* Tooltip when collapsed */
        #sidebar.collapsed .nav-item::after {
            content: attr(data-tip);
            position: absolute; left: calc(100% + 10px); top: 50%;
            transform: translateY(-50%);
            background: #1e1e1e; color: #e5e7eb;
            padding: 4px 10px; border-radius: 6px;
            font-size: 12px; white-space: nowrap;
            opacity: 0; pointer-events: none;
            transition: opacity .12s; z-index: 999;
        }
        #sidebar.collapsed .nav-item:hover::after { opacity: 1; }

        /* ── Scrollbar ───────────────────────────────────────────── */
        ::-webkit-scrollbar { width: 5px; height: 5px; }
        ::-webkit-scrollbar-track { background: #0d0d0d; }
        ::-webkit-scrollbar-thumb { background: #222; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #76B900; }

        /* ── Misc ────────────────────────────────────────────────── */
        .card-lift { transition: box-shadow .2s, transform .2s; }
        .card-lift:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(0,0,0,.14); }

        .toast { animation: slideIn .3s ease; }
        @keyframes slideIn { from { transform:translateX(110%); opacity:0; } to { transform:translateX(0); opacity:1; } }

        input:focus, select:focus, textarea:focus {
            outline: none !important;
            border-color: #76B900 !important;
            box-shadow: 0 0 0 3px rgba(118,185,0,.18) !important;
        }
        .btn-brand { background:#76B900; color:#000; font-weight:800; transition:background .15s,box-shadow .15s; }
        .btn-brand:hover { background:#8ecf00; box-shadow:0 0 20px rgba(118,185,0,.4); }
    </style>
    <?php if (isset($extraHead)) echo $extraHead; ?>
</head>
<body class="bg-slate-100 text-slate-800 flex h-screen overflow-hidden">

<!-- ═══════════════ SIDEBAR ═══════════════ -->
<aside id="sidebar" style="background:#0a0a0a; border-right:1px solid #181818; display:flex; flex-direction:column; height:100vh;">

    <!-- Brand + toggle -->
    <div style="border-bottom:1px solid #1c1c1c; padding:12px; display:flex; align-items:center; gap:10px; flex-shrink:0;">
        <!-- Logo — FIRST so it stays visible when collapsed; click it to toggle the sidebar -->
        <div onclick="toggleSidebar()" title="Toggle sidebar"
             style="width:28px; height:28px; flex-shrink:0; cursor:pointer; border-radius:6px; padding:1px; transition:background .15s;"
             onmouseover="this.style.background='rgba(118,185,0,.15)'"
             onmouseout="this.style.background='transparent'">
            <svg viewBox="0 0 36 36" fill="none" style="width:100%;height:100%;">
                <circle cx="18" cy="18" r="15.5" stroke="#76B900" stroke-width="2.2"/>
                <path d="M6 31 C10 24 18 13 32 7" stroke="#76B900" stroke-width="2.2" stroke-linecap="round"/>
                <path d="M4 21 C9 17 21 11 34 9" stroke="#76B900" stroke-width="1.1" stroke-linecap="round" opacity="0.38"/>
            </svg>
        </div>
        <!-- Brand name — hidden when collapsed -->
        <div class="sb-text flex-1">
            <p class="brand-font" style="color:#76B900; font-size:1.35rem; line-height:1; letter-spacing:.06em;">Padel07</p>
            <p style="font-size:.58rem; color:#333; letter-spacing:.08em; text-transform:uppercase; margin-top:1px;">Hasbaya Padel Club</p>
        </div>
    </div>

    <!-- Navigation -->
    <nav style="flex:1; padding:10px 8px; overflow-y:auto; display:flex; flex-direction:column; gap:2px;">
        <?php foreach ($navItems as $key => $item): ?>
        <a href="<?= $item['href'] ?>"
           data-tip="<?= htmlspecialchars($item['label']) ?>"
           class="nav-item <?= $activePage === $key ? 'active' : '' ?>">
            <i class="fa-solid <?= $item['icon'] ?>" style="width:16px; text-align:center; font-size:13px; flex-shrink:0;"></i>
            <span class="sb-text"><?= $item['label'] ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <!-- User + sign out -->
    <div style="border-top:1px solid #1c1c1c; padding:10px 8px; flex-shrink:0;">
        <div style="display:flex; align-items:center; gap:10px; padding:6px 4px 8px;">
            <div style="width:28px; height:28px; border-radius:50%; background:#76B900; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:900; color:#000; flex-shrink:0;">
                <?= strtoupper(substr($userName, 0, 1)) ?>
            </div>
            <div class="sb-text">
                <p style="font-size:12px; font-weight:600; color:#ccc; line-height:1.2;"><?= htmlspecialchars($userName) ?></p>
                <p style="font-size:10px; color:#444; text-transform:capitalize;"><?= htmlspecialchars($userRole) ?></p>
            </div>
        </div>
        <a href="logout.php" data-tip="Sign Out"
           class="nav-item" style="color:#3a3a3a;"
           onmouseover="this.style.color='#f87171'" onmouseout="this.style.color='#3a3a3a'">
            <i class="fa-solid fa-right-from-bracket" style="width:16px; text-align:center; font-size:12px; flex-shrink:0;"></i>
            <span class="sb-text" style="font-size:12px;">Sign Out</span>
        </a>
    </div>
</aside>

<!-- ═══════════════ MAIN WRAPPER ═══════════════ -->
<div class="flex-1 flex flex-col min-w-0 overflow-hidden">

<!-- Toast container -->
<div id="toast-container" class="fixed top-4 right-4 z-[200] space-y-2 pointer-events-none"></div>

<script>
/* ── Sidebar collapse ── */
(function () {
    const sidebar = document.getElementById('sidebar');
    if (localStorage.getItem('sb_collapsed') === '1') sidebar.classList.add('collapsed');

    window.toggleSidebar = function () {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sb_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
    };
})();

/* ── Toast ── */
function showToast(msg, type = 'success') {
    const c  = document.getElementById('toast-container');
    const el = document.createElement('div');
    const bg = type === 'success' ? '#76B900' : type === 'error' ? '#e11d48' : type === 'warn' ? '#f59e0b' : '#374151';
    const clr = (type === 'success' || type === 'warn') ? '#000' : '#fff';
    el.className = 'toast pointer-events-auto text-sm font-bold px-4 py-3 rounded-xl shadow-xl flex items-center gap-2 max-w-xs';
    el.style.cssText = `background:${bg}; color:${clr};`;
    el.innerHTML = `<i class="fa-solid ${type==='success'?'fa-check-circle':type==='error'?'fa-circle-xmark':'fa-triangle-exclamation'}"></i><span>${msg}</span>`;
    c.appendChild(el);
    setTimeout(() => el.remove(), 3500);
}
</script>
