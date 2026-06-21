<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/settings.php';
requireAuth();

$autoPrintReceipt = (int)Settings::get('auto_print_receipt', 0);
$autoOpenDrawer   = (int)Settings::get('auto_open_drawer', 0);
$showNumpad       = (int)Settings::get('show_numpad', 1);
$lbpRate          = Settings::lbpRate();

$db        = Database::getInstance();
$tableId   = (int)($_GET['table_id'] ?? 0);
$orderId   = (int)($_GET['order_id'] ?? 0);
$orderType = ($_GET['type'] ?? '') === 'takeaway' ? 'takeaway' : 'dine_in';

$tableInfo = $tableId ? $db->fetchOne('SELECT * FROM restaurant_tables WHERE id = ?', [$tableId]) : null;

$pageLabel  = $tableInfo ? 'Table ' . htmlspecialchars($tableInfo['table_number']) : 'Takeout Order';
$pageTitle  = "POS – $pageLabel";
$activePage = 'pos';
include __DIR__ . '/includes/header.php';
?>

<!-- TOP BAR -->
<header class="bg-white border-b border-slate-200 px-5 py-3 flex items-center gap-3 flex-shrink-0">
    <a href="dashboard.php" class="p-2 rounded-lg hover:bg-slate-100 text-slate-500 hover:text-slate-700 transition">
        <i class="fa-solid fa-arrow-left"></i>
    </a>
    <div class="flex items-center gap-3">
        <?php if ($tableInfo): ?>
            <div class="w-9 h-9 rounded-xl flex items-center justify-center font-black text-sm text-black" style="background:#76B900;">
                <?= htmlspecialchars($tableInfo['table_number']) ?>
            </div>
            <div>
                <p class="font-bold text-slate-800 leading-tight">Table <?= htmlspecialchars($tableInfo['table_number']) ?></p>
                <p class="text-xs text-slate-400"><?= htmlspecialchars($tableInfo['section']) ?></p>
            </div>
        <?php else: ?>
            <div class="w-9 h-9 rounded-xl flex items-center justify-center text-black" style="background:#76B900;">
                <i class="fa-solid fa-bag-shopping text-sm"></i>
            </div>
            <div>
                <p class="font-bold text-slate-800">Takeout Order</p>
                <p class="text-xs text-slate-400">No table assigned</p>
            </div>
        <?php endif; ?>
    </div>
    <div class="ml-auto flex items-center gap-2">
        <div id="order-status-badge" class="hidden text-xs font-medium px-3 py-1 rounded-full"></div>
        <button onclick="POS.toggleSearch()" class="p-2 rounded-lg hover:bg-slate-100 text-slate-500 transition">
            <i class="fa-solid fa-magnifying-glass"></i>
        </button>
    </div>
</header>

<!-- Search bar -->
<div id="search-bar" class="hidden bg-white border-b border-slate-200 px-5 py-2">
    <input id="search-input" type="text" placeholder="Search menu items…"
           oninput="POS.filterItems(this.value)"
           class="w-full bg-slate-100 rounded-lg px-4 py-2 text-sm text-slate-800 border-0 outline-none">
</div>

<!-- POS BODY -->
<div class="flex-1 flex overflow-hidden">

    <!-- LEFT: Menu -->
    <div class="flex-1 flex flex-col min-w-0 border-r border-slate-200">
        <!-- Category tabs -->
        <div id="category-tabs" class="bg-white border-b border-slate-200 px-3 py-2 flex gap-1.5 overflow-x-auto flex-shrink-0">
            <span class="text-xs text-slate-400 self-center px-2">Loading…</span>
        </div>
        <!-- Menu grid -->
        <div class="flex-1 overflow-y-auto p-4">
            <div id="menu-grid" class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3">
                <?php for ($i=0;$i<8;$i++): ?>
                <div class="bg-white rounded-xl h-28 animate-pulse shadow-sm"></div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT: Cart -->
    <div class="w-80 xl:w-96 flex flex-col bg-white flex-shrink-0">
        <div class="flex-1 overflow-y-auto" id="cart-panel">
            <div class="flex flex-col items-center justify-center h-full text-slate-300 py-12 px-4 text-center">
                <i class="fa-solid fa-basket-shopping text-5xl mb-4"></i>
                <p class="font-semibold text-slate-400">Order is empty</p>
                <p class="text-sm mt-1 text-slate-300">Tap items to add them</p>
            </div>
        </div>
    </div>
</div>

<!-- CHECKOUT MODAL -->
<div id="checkout-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-3">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm flex flex-col" style="max-height:92vh;">

        <!-- Compact header — shows total prominently, replaces the separate summary strip -->
        <div class="px-5 py-4 text-white flex-shrink-0 rounded-t-2xl" style="background:#0a0a0a;">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-zinc-500 text-xs font-semibold uppercase tracking-wider mb-0.5">Order Total</p>
                    <p id="co-total" class="text-4xl font-black leading-none">—</p>
                    <p id="co-breakdown" class="text-zinc-500 text-xs mt-1.5 truncate">—</p>
                </div>
                <button onclick="POS.closeCheckout()" class="text-zinc-400 hover:text-white text-2xl leading-none flex-shrink-0 mt-1">&times;</button>
            </div>
        </div>

        <!-- Scrollable content -->
        <div class="overflow-y-auto flex-1 px-5 pt-4 pb-2">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Payment Method</p>
            <div class="grid grid-cols-3 gap-2 mb-4">
                <button onclick="POS.setPayment('cash')"  id="pm-cash"  class="pm-btn active-pm py-2.5 rounded-xl border-2 text-sm font-bold"><i class="fa-solid fa-money-bill-wave mr-1"></i>Cash</button>
                <button onclick="POS.setPayment('card')"  id="pm-card"  class="pm-btn py-2.5 rounded-xl border-2 border-slate-200 text-slate-600 text-sm font-bold hover:border-slate-300"><i class="fa-solid fa-credit-card mr-1"></i>Card</button>
                <button onclick="POS.setPayment('other')" id="pm-other" class="pm-btn py-2.5 rounded-xl border-2 border-slate-200 text-slate-600 text-sm font-bold hover:border-slate-300"><i class="fa-solid fa-ellipsis mr-1"></i>Other</button>
            </div>

            <div id="cash-section">
                <?php if ($lbpRate > 0): ?>
                <!-- Currency toggle -->
                <div class="flex gap-2 mb-3">
                    <button type="button" id="cur-usd" onclick="POS.setCurrency('USD')"
                            class="flex-1 py-2 rounded-xl border-2 text-sm font-bold transition"
                            style="background:#f2fce0;border-color:#76B900;color:#436800;">
                        <i class="fa-solid fa-dollar-sign mr-1"></i>USD
                    </button>
                    <button type="button" id="cur-lbp" onclick="POS.setCurrency('LBP')"
                            class="flex-1 py-2 rounded-xl border-2 text-sm font-bold transition"
                            style="border-color:#e2e8f0;color:#64748b;">
                        ل.ل&nbsp; LBP
                    </button>
                </div>
                <?php endif; ?>

                <p id="tendered-label" class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Amount Tendered</p>

                <!-- Numpad display -->
                <div id="numpad-display" class="hidden text-center font-black text-slate-800 py-2 px-4 bg-slate-50 rounded-xl mb-2"
                     style="font-size:2rem;letter-spacing:-1px;"><?= CURRENCY_SYMBOL ?>0</div>

                <!-- Regular keyboard input -->
                <input type="number" id="amount-tendered" min="0" step="0.01" placeholder="0.00" oninput="POS.calcChange()"
                       class="w-full border-2 border-slate-200 rounded-xl px-4 py-2.5 text-2xl font-bold text-slate-800 outline-none mb-2">

                <!-- Quick amounts -->
                <div class="grid grid-cols-4 gap-1.5 mb-2" id="quick-amounts"></div>

                <!-- Numpad grid -->
                <div id="numpad-grid" class="hidden mb-2">
                    <div class="grid grid-cols-3 gap-1.5 mb-1.5">
                        <button type="button" onclick="POS.numpad('7')" class="numpad-key">7</button>
                        <button type="button" onclick="POS.numpad('8')" class="numpad-key">8</button>
                        <button type="button" onclick="POS.numpad('9')" class="numpad-key">9</button>
                        <button type="button" onclick="POS.numpad('4')" class="numpad-key">4</button>
                        <button type="button" onclick="POS.numpad('5')" class="numpad-key">5</button>
                        <button type="button" onclick="POS.numpad('6')" class="numpad-key">6</button>
                        <button type="button" onclick="POS.numpad('1')" class="numpad-key">1</button>
                        <button type="button" onclick="POS.numpad('2')" class="numpad-key">2</button>
                        <button type="button" onclick="POS.numpad('3')" class="numpad-key">3</button>
                        <button type="button" id="numpad-dot" onclick="POS.numpad('.')" class="numpad-key" style="font-size:1.3rem;">.</button>
                        <button type="button" onclick="POS.numpad('0')" class="numpad-key">0</button>
                        <button type="button" onclick="POS.numpad('back')" class="numpad-key" style="color:#ef4444;font-size:1.1rem;">⌫</button>
                    </div>
                    <button type="button" onclick="POS.numpadExact()" id="numpad-exact-btn"
                            class="w-full py-2 rounded-xl text-sm font-bold"
                            style="background:#f2fce0;color:#436800;border:2px solid #c6ee6e;">
                        Exact — <span id="numpad-exact-label"><?= CURRENCY_SYMBOL ?>0.00</span>
                    </button>
                </div>

                <div class="flex justify-between items-center rounded-xl px-4 py-2.5 border-2" style="background:#f2fce0;border-color:#c6ee6e;">
                    <span class="text-sm font-bold" style="color:#436800;">Change Due</span>
                    <div id="change-due" class="text-right font-black" style="color:#436800;font-size:1.2rem;"><?= CURRENCY_SYMBOL ?>0.00</div>
                </div>
            </div>

            <?php if ($orderType === 'takeaway'): ?>
            <div class="mt-3">
                <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Customer Name <span class="font-normal text-slate-300">(optional)</span></p>
                <input type="text" id="customer-name" placeholder="Customer name"
                       class="w-full border-2 border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none">
            </div>
            <?php endif; ?>
        </div>

        <!-- Fixed footer -->
        <div class="px-5 py-3 flex gap-2 flex-shrink-0 border-t border-slate-100">
            <button onclick="POS.closeCheckout()" class="flex-1 py-3 rounded-xl border-2 border-slate-200 text-slate-600 font-bold text-sm hover:bg-slate-50">Cancel</button>
            <button onclick="POS.processCheckout()" id="confirm-btn"
                    class="flex-[2] py-3 rounded-xl text-black font-black text-sm transition" style="background:#76B900;">
                <i class="fa-solid fa-check mr-1.5"></i> Confirm Payment
            </button>
        </div>
    </div>
</div>

<!-- RECEIPT / SUCCESS MODAL -->
<div id="receipt-modal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xs overflow-hidden">
        <div class="text-center px-6 py-5 border-b">
            <div class="w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-3" style="background:#f2fce0;">
                <i class="fa-solid fa-circle-check text-2xl" style="color:#76B900;"></i>
            </div>
            <h2 class="font-bold text-lg text-slate-800">Payment Complete</h2>
            <p class="text-sm text-slate-500 mt-1" id="receipt-summary"></p>
        </div>
        <div id="receipt-body" class="px-6 py-4 text-sm space-y-1 max-h-52 overflow-y-auto"></div>
        <div class="px-6 pb-5 flex gap-2">
            <button onclick="POS.printReceipt()" class="flex-1 py-2.5 rounded-xl border-2 border-slate-200 text-slate-600 font-semibold text-sm hover:bg-slate-50">
                <i class="fa-solid fa-print mr-1"></i> Print
            </button>
            <button onclick="window.location='dashboard.php'" class="flex-1 py-2.5 rounded-xl text-black font-bold text-sm" style="background:#76B900;">
                <i class="fa-solid fa-house mr-1"></i> Floor
            </button>
        </div>
    </div>
</div>

<style>
.pm-btn.active-pm { border-color:#76B900; background:#f2fce0; color:#436800; }
.menu-item-card { transition: box-shadow .15s, transform .1s, background .1s; }
.menu-item-card:active { transform: scale(0.96); }
.numpad-key {
    background:white; border:2px solid #e2e8f0; border-radius:10px;
    padding:10px 6px; font-size:1.15rem; font-weight:700; color:#1e293b;
    cursor:pointer; user-select:none; -webkit-tap-highlight-color:transparent;
    transition:background .1s, transform .08s; width:100%;
}
.numpad-key:active { background:#f2fce0; transform:scale(0.92); }
</style>

<script>
const POS = (() => {
    const CURRENCY    = '<?= CURRENCY_SYMBOL ?>';
    const AUTO_PRINT  = <?= $autoPrintReceipt ? 'true' : 'false' ?>;
    const AUTO_DRAWER = <?= $autoOpenDrawer   ? 'true' : 'false' ?>;
    const SHOW_NUMPAD = <?= $showNumpad       ? 'true' : 'false' ?>;
    const LBP_RATE    = <?= $lbpRate ?>;

    let _numpadVal = '';
    let _tendCur   = 'USD';

    function lbpFmt(n) { return Math.round(n).toLocaleString(); }

    const state = {
        tableId:    <?= $tableId ?: 'null' ?>,
        orderType:  '<?= $orderType ?>',
        orderId:    <?= $orderId ?: 'null' ?>,
        payment:    'cash',
        total:      0,
        subtotal:   0,
        tax:        0,
        searchOpen: false,
        allItems:   [],
        lastReceiptId: null,
    };

    // ── API ──────────────────────────────────────────────────────────────────
    async function api(action, body = null, method = 'GET') {
        let url = `api.php?action=${action}`;
        const opts = { method };
        if (body && method === 'GET') {
            url += '&' + new URLSearchParams(body);
        } else if (body) {
            opts.headers = { 'Content-Type': 'application/json' };
            opts.body = JSON.stringify(body);
        }
        const r = await fetch(url, opts);
        return r.json();
    }

    // ── Categories ───────────────────────────────────────────────────────────
    async function loadCategories() {
        const res = await api('get_categories');
        if (!res.success || !res.data.length) {
            document.getElementById('category-tabs').innerHTML =
                '<p class="text-xs text-slate-400 px-2 py-1">No categories yet — add them in the Menu tab</p>';
            document.getElementById('menu-grid').innerHTML = '';
            return;
        }
        const tabs = document.getElementById('category-tabs');
        tabs.innerHTML =
            `<button id="cat-btn-0" onclick="POS.loadMenuItems(0)"
                     class="flex-shrink-0 px-3 py-1.5 rounded-lg text-sm font-medium transition-all text-slate-600 hover:bg-slate-100">
                 <i class="fa-solid fa-border-all mr-1 text-xs"></i>All
             </button>` +
            res.data.map(c => `
            <button id="cat-btn-${c.id}" onclick="POS.loadMenuItems(${c.id})"
                    class="flex-shrink-0 px-3 py-1.5 rounded-lg text-sm font-medium transition-all text-slate-600 hover:bg-slate-100">
                ${esc(c.name)}
            </button>`).join('');
        loadMenuItems(0);
    }

    // ── Menu items ───────────────────────────────────────────────────────────
    async function loadMenuItems(catId) {
        document.querySelectorAll('[id^="cat-btn-"]').forEach(b => {
            b.className = 'flex-shrink-0 px-3 py-1.5 rounded-lg text-sm font-medium transition-all text-slate-600 hover:bg-slate-100';
        });
        const active = document.getElementById(`cat-btn-${catId}`);
        if (active) active.className = 'flex-shrink-0 px-3 py-1.5 rounded-lg text-sm font-bold text-black rounded-lg' ;
        if (active) active.style.cssText = 'background:#76B900; color:#000; border-radius:8px; padding:6px 12px;';

        const grid = document.getElementById('menu-grid');
        grid.innerHTML = Array(6).fill('<div class="bg-slate-100 rounded-xl h-28 animate-pulse"></div>').join('');

        const res = await api('get_menu', { category_id: catId });
        state.allItems = res.success ? res.data : [];
        renderGrid(state.allItems);
    }

    function renderGrid(items) {
        const grid = document.getElementById('menu-grid');
        if (!items.length) {
            grid.innerHTML = '<p class="col-span-full text-center text-slate-400 py-12 text-sm">No items in this category</p>';
            return;
        }
        grid.innerHTML = items.map(item => `
            <div onclick="POS.addItem(${item.id})" data-item-id="${item.id}"
                 class="menu-item-card bg-white rounded-xl p-3 shadow-sm cursor-pointer select-none border border-slate-100
                        hover:shadow-md hover:border-brand-200 ${!parseInt(item.is_available)?'opacity-40 pointer-events-none':''}">
                <div class="font-semibold text-slate-800 text-sm leading-tight mb-1 line-clamp-2">${esc(item.name)}</div>
                <div class="text-xs text-slate-400 mb-2 line-clamp-2">${esc(item.description||'')}</div>
                <div class="flex items-center justify-between">
                    <span class="font-black text-sm" style="color:#76B900;">${CURRENCY}${parseFloat(item.price).toFixed(2)}</span>
                    ${parseInt(item.is_available)?'<span class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold text-black" style="background:#f2fce0;">+</span>':'<span class="text-xs text-rose-500">Unavail.</span>'}
                </div>
                ${item.track_stock && item.stock_count !== null ? `<p class="text-xs text-amber-500 mt-1">${item.stock_count} left</p>`:''}
            </div>`).join('');
    }

    function filterItems(q) {
        if (!q.trim()) { renderGrid(state.allItems); return; }
        const lq = q.toLowerCase();
        renderGrid(state.allItems.filter(i => i.name.toLowerCase().includes(lq) || (i.description||'').toLowerCase().includes(lq)));
    }

    // ── Order ────────────────────────────────────────────────────────────────
    async function checkExistingOrder() {
        if (state.orderId) {
            const res = await api('get_order', { order_id: state.orderId });
            if (res.success) { renderCart(res.data); return; }
        }
        if (state.tableId) {
            const res = await api('get_active_order', { table_id: state.tableId });
            if (res.success && res.data) {
                state.orderId = res.data.id;      // ← use .id not .order_id
                renderCart(res.data);
                return;
            }
        }
        renderEmptyCart();
    }

    async function addItem(menuItemId) {
        // Tap feedback
        const card = document.querySelector(`[data-item-id="${menuItemId}"]`);
        if (card) { card.style.background='#f2fce0'; setTimeout(()=>card.style.background='',250); }

        const body = { menu_item_id: menuItemId, quantity: 1, order_type: state.orderType };
        if (state.orderId) body.order_id = state.orderId;
        if (state.tableId) body.table_id = state.tableId;

        const res = await api('add_item', body, 'POST');
        if (res.success) {
            state.orderId = res.data.id;          // ← BUG FIX: was res.data.order_id
            renderCart(res.data);
        } else {
            showToast(res.error || 'Could not add item', 'error');
        }
    }

    async function updateQty(orderItemId, newQty) {
        if (newQty < 1) { removeItem(orderItemId); return; }
        const res = await api('update_item', { order_item_id: orderItemId, quantity: newQty }, 'POST');
        if (res.success) renderCart(res.data);
    }

    async function removeItem(orderItemId) {
        const res = await api('remove_item', { order_item_id: orderItemId }, 'POST');
        if (res.success) {
            if (!res.data.items || !res.data.items.length) { state.orderId = null; renderEmptyCart(); }
            else renderCart(res.data);
        }
    }

    async function cancelOrder() {
        if (!confirm('Cancel this entire order?')) return;
        const res = await api('cancel_order', { order_id: state.orderId }, 'POST');
        if (res.success) { showToast('Order cancelled', 'warn'); state.orderId = null; renderEmptyCart(); }
    }

    // ── Cart render ──────────────────────────────────────────────────────────
    function renderCart(order) {
        state.total    = parseFloat(order.total);
        state.subtotal = parseFloat(order.subtotal);
        state.tax      = parseFloat(order.tax_amount);
        state.lastReceiptId = order.id;

        const badge = document.getElementById('order-status-badge');
        badge.className = 'text-xs font-medium px-3 py-1 rounded-full bg-slate-100 text-slate-600';
        badge.textContent = `#${order.id} · Pending`;
        badge.classList.remove('hidden');

        document.getElementById('cart-panel').innerHTML = `
            <div class="p-4 flex flex-col h-full">
                <div class="flex-1 overflow-y-auto -mx-1 px-1 space-y-1.5 mb-3 min-h-0">
                    ${order.items.map(i => `
                    <div class="flex items-center gap-2 bg-slate-50 rounded-xl px-3 py-2">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-slate-800 truncate">${esc(i.item_name)}</p>
                            <p class="text-xs text-slate-400">${CURRENCY}${parseFloat(i.unit_price).toFixed(2)} each</p>
                        </div>
                        <div class="flex items-center gap-1 flex-shrink-0">
                            <button onclick="POS.updateQty(${i.id},${i.quantity-1})"
                                class="w-6 h-6 rounded-full bg-slate-200 hover:bg-slate-300 flex items-center justify-center text-xs font-bold">−</button>
                            <span class="w-5 text-center text-sm font-bold">${i.quantity}</span>
                            <button onclick="POS.updateQty(${i.id},${i.quantity+1})"
                                class="w-6 h-6 rounded-full bg-slate-200 hover:bg-slate-300 flex items-center justify-center text-xs font-bold">+</button>
                        </div>
                        <span class="text-sm font-bold text-slate-700 w-14 text-right flex-shrink-0">${CURRENCY}${parseFloat(i.subtotal).toFixed(2)}</span>
                        <button onclick="POS.removeItem(${i.id})" class="text-slate-300 hover:text-rose-500 transition ml-0.5 flex-shrink-0">
                            <i class="fa-solid fa-xmark text-xs"></i>
                        </button>
                    </div>`).join('')}
                </div>

                <div class="border-t border-slate-100 pt-3 space-y-1">
                    <div class="flex justify-between text-sm text-slate-500"><span>Subtotal</span><span>${CURRENCY}${parseFloat(order.subtotal).toFixed(2)}</span></div>
                    <div class="flex justify-between text-sm text-slate-500"><span>Tax (${parseFloat(order.tax_rate)}%)</span><span>${CURRENCY}${parseFloat(order.tax_amount).toFixed(2)}</span></div>
                    <div class="flex justify-between font-black text-slate-800 text-lg border-t border-slate-200 pt-2 mt-1">
                        <span>Total</span>
                        <div class="text-right">
                            <div>${CURRENCY}${parseFloat(order.total).toFixed(2)}</div>
                            ${LBP_RATE > 0 ? `<div style="font-size:.7rem;font-weight:500;color:#94a3b8;">LBP ${lbpFmt(parseFloat(order.total) * LBP_RATE)}</div>` : ''}
                        </div>
                    </div>
                </div>

                <div class="mt-4 space-y-2">
                    <button onclick="POS.openCheckout()"
                            class="w-full py-3.5 rounded-xl text-black font-black text-base transition shadow-lg"
                            style="background:#76B900; box-shadow:0 4px 15px rgba(118,185,0,.3);">
                        <i class="fa-solid fa-credit-card mr-2"></i>Checkout — ${CURRENCY}${parseFloat(order.total).toFixed(2)}
                    </button>
                    <div class="flex gap-2">
                        <button onclick="window.open('receipt.php?order_id=${order.id}','_blank','width=400,height=600')"
                                class="flex-1 py-2.5 rounded-xl border-2 border-slate-200 hover:bg-slate-50 text-slate-600 font-semibold text-sm transition">
                            <i class="fa-solid fa-print mr-1.5"></i>Print Bill
                        </button>
                        <button onclick="POS.cancelOrder()"
                                class="flex-1 py-2.5 rounded-xl border-2 border-rose-200 hover:bg-rose-50 text-rose-500 font-semibold text-sm transition">
                            <i class="fa-solid fa-ban mr-1.5"></i>Cancel
                        </button>
                    </div>
                </div>
            </div>`;
    }

    function renderEmptyCart() {
        document.getElementById('order-status-badge').classList.add('hidden');
        document.getElementById('cart-panel').innerHTML = `
            <div class="flex flex-col items-center justify-center h-full text-slate-300 py-12 px-4 text-center">
                <i class="fa-solid fa-basket-shopping text-5xl mb-4"></i>
                <p class="font-semibold text-slate-400">Order is empty</p>
                <p class="text-sm mt-1 text-slate-300">Tap a menu item to add it</p>
            </div>`;
    }

    // ── Checkout ─────────────────────────────────────────────────────────────
    function openCheckout() {
        if (!state.orderId) { showToast('No active order', 'warn'); return; }
        document.getElementById('co-total').textContent = CURRENCY + state.total.toFixed(2);
        let breakdown = `Subtotal ${CURRENCY}${state.subtotal.toFixed(2)}  ·  Tax ${CURRENCY}${state.tax.toFixed(2)}`;
        if (LBP_RATE > 0) breakdown += `  ·  LBP ${lbpFmt(state.total * LBP_RATE)}`;
        document.getElementById('co-breakdown').textContent = breakdown;
        document.getElementById('amount-tendered').value = '';
        document.getElementById('change-due').innerHTML  = CURRENCY + '0.00';
        _numpadVal = '';
        _tendCur   = 'USD';
        setCurrency('USD');
        _updateNumpadDisplay();
        buildQuickAmounts();
        _applyNumpad(state.payment === 'cash');
        document.getElementById('checkout-modal').classList.remove('hidden');
    }

    function closeCheckout() { document.getElementById('checkout-modal').classList.add('hidden'); }

    function buildQuickAmounts() {
        const t = state.total;
        let amts, labels;
        if (_tendCur === 'LBP' && LBP_RATE > 0) {
            const tLBP = t * LBP_RATE;
            const units = [50000, 100000, 250000, 500000];
            amts   = [...new Set(units.map(u => Math.ceil(tLBP / u) * u))].slice(0, 4);
            labels = amts.map(a => `LBP ${lbpFmt(a)}`);
        } else {
            amts   = [...new Set([Math.ceil(t/5)*5, Math.ceil(t/10)*10, Math.ceil(t/20)*20, Math.ceil(t/50)*50])].slice(0,4);
            labels = amts.map(a => `${CURRENCY}${a.toFixed(2)}`);
        }
        document.getElementById('quick-amounts').innerHTML = amts.map((a, i) =>
            `<button onclick="POS.setTendered(${a})"
                     class="py-1.5 bg-slate-100 hover:bg-slate-200 rounded-lg text-xs font-bold text-slate-700 transition truncate px-1">
                ${labels[i]}</button>`).join('');
    }

    function setTendered(a) {
        if (_tendCur === 'LBP') {
            _numpadVal = String(Math.round(a));
            document.getElementById('amount-tendered').value = Math.round(a);
        } else {
            _numpadVal = a.toFixed(2);
            document.getElementById('amount-tendered').value = _numpadVal;
        }
        _updateNumpadDisplay();
        calcChange();
    }

    function calcChange() {
        const t = parseFloat(document.getElementById('amount-tendered').value) || 0;
        const el = document.getElementById('change-due');
        if (_tendCur === 'LBP' && LBP_RATE > 0) {
            const changeLBP = Math.max(0, Math.round(t - state.total * LBP_RATE));
            const changeUSD = changeLBP / LBP_RATE;
            el.innerHTML = changeLBP > 0
                ? `LBP ${lbpFmt(changeLBP)}<div style="font-size:.75rem;font-weight:500;opacity:.65;">≈ ${CURRENCY}${changeUSD.toFixed(2)}</div>`
                : 'LBP 0';
        } else {
            const c = Math.max(0, t - state.total);
            el.innerHTML = LBP_RATE > 0 && c > 0
                ? `${CURRENCY}${c.toFixed(2)}<div style="font-size:.75rem;font-weight:500;opacity:.65;">≈ LBP ${lbpFmt(c * LBP_RATE)}</div>`
                : CURRENCY + c.toFixed(2);
        }
    }

    function setPayment(m) {
        state.payment = m;
        document.querySelectorAll('.pm-btn').forEach(b => {
            b.classList.remove('active-pm');
            b.style.cssText = '';
            b.classList.add('border-slate-200','text-slate-600');
        });
        const btn = document.getElementById(`pm-${m}`);
        if (btn) { btn.classList.add('active-pm'); btn.classList.remove('border-slate-200','text-slate-600'); }
        const isCash = m === 'cash';
        document.getElementById('cash-section').style.display = isCash ? '' : 'none';
        if (isCash) _applyNumpad(true);
    }

    async function processCheckout() {
        const tendRaw = parseFloat(document.getElementById('amount-tendered').value) || 0;
        let amountUSD = tendRaw, amountLBP = 0;
        if (_tendCur === 'LBP' && LBP_RATE > 0) {
            amountLBP = Math.round(tendRaw);
            amountUSD = amountLBP / LBP_RATE;
        }
        if (state.payment === 'cash' && amountUSD < state.total) {
            showToast('Amount tendered is less than total', 'warn'); return;
        }
        const btn = document.getElementById('confirm-btn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1.5"></i>Processing…';

        const res = await api('checkout', {
            order_id:            state.orderId,
            payment_method:      state.payment,
            tender_currency:     _tendCur,
            amount_tendered:     amountUSD,
            amount_tendered_lbp: amountLBP,
            customer_name:       document.getElementById('customer-name')?.value || '',
        }, 'POST');

        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check mr-1.5"></i> Confirm Payment';

        if (res.success) {
            closeCheckout();
            showReceiptModal(res.data);
            state.orderId = null;
            if (AUTO_PRINT)  printReceipt();
            if (AUTO_DRAWER) kickCashDrawer();
        } else {
            showToast(res.error || 'Checkout failed', 'error');
        }
    }

    function showReceiptModal(data) {
        state.lastReceiptId = data.order_id;
        const rate = data.lbp_rate || LBP_RATE;
        document.getElementById('receipt-summary').textContent =
            `${data.table_number || 'Takeout'} · ${CURRENCY}${parseFloat(data.total).toFixed(2)}`;
        let html = data.items.map(i =>
            `<div class="flex justify-between text-slate-600"><span>${esc(i.item_name)} ×${i.quantity}</span><span>${CURRENCY}${parseFloat(i.subtotal).toFixed(2)}</span></div>`
        ).join('');
        html += `<div class="border-t pt-2 mt-2 flex justify-between font-bold text-slate-800"><span>Total</span><span>${CURRENCY}${parseFloat(data.total).toFixed(2)}</span></div>`;
        if (rate > 0)
            html += `<div class="flex justify-between text-xs text-slate-400 mt-0.5"><span>Total (LBP)</span><span>LBP ${lbpFmt(data.total * rate)}</span></div>`;
        if (data.change_due > 0 || data.change_due_lbp > 0) {
            const changeStr = data.tender_currency === 'LBP' && data.change_due_lbp > 0
                ? `LBP ${lbpFmt(data.change_due_lbp)}`
                : `${CURRENCY}${parseFloat(data.change_due).toFixed(2)}`;
            html += `<div class="flex justify-between font-bold mt-1" style="color:#436800;"><span>Change</span><span>${changeStr}</span></div>`;
        }
        document.getElementById('receipt-body').innerHTML = html;
        document.getElementById('receipt-modal').classList.remove('hidden');
    }

    function setCurrency(cur) {
        _tendCur = cur;
        const usdBtn = document.getElementById('cur-usd');
        const lbpBtn = document.getElementById('cur-lbp');
        if (usdBtn) {
            usdBtn.style.cssText = cur === 'USD'
                ? 'background:#f2fce0;border-color:#76B900;color:#436800;'
                : 'border-color:#e2e8f0;color:#64748b;background:white;';
        }
        if (lbpBtn) {
            lbpBtn.style.cssText = cur === 'LBP'
                ? 'background:#f2fce0;border-color:#76B900;color:#436800;'
                : 'border-color:#e2e8f0;color:#64748b;background:white;';
        }
        // Update label
        const lbl = document.getElementById('tendered-label');
        if (lbl) lbl.textContent = cur === 'LBP' ? 'AMOUNT TENDERED (LBP)' : 'AMOUNT TENDERED';
        // Show/hide decimal in numpad (LBP = integers only)
        const dotBtn = document.getElementById('numpad-dot');
        if (dotBtn) dotBtn.style.visibility = cur === 'LBP' ? 'hidden' : 'visible';
        // Update exact button
        const exactLbl = document.getElementById('numpad-exact-label');
        if (exactLbl) exactLbl.textContent = cur === 'LBP' && LBP_RATE > 0
            ? `LBP ${lbpFmt(state.total * LBP_RATE)}`
            : CURRENCY + state.total.toFixed(2);
        // Reset input
        _numpadVal = '';
        document.getElementById('amount-tendered').value = '';
        _updateNumpadDisplay();
        calcChange();
        buildQuickAmounts();
    }

    // ── Numpad ───────────────────────────────────────────────────────────────
    function numpad(key) {
        if (key === 'back') {
            _numpadVal = _numpadVal.slice(0, -1);
        } else if (key === '.') {
            if (_numpadVal.includes('.')) return;
            _numpadVal = (_numpadVal || '0') + '.';
        } else {
            const dotIdx = _numpadVal.indexOf('.');
            if (dotIdx !== -1 && _numpadVal.length - dotIdx > 2) return;
            _numpadVal = (_numpadVal === '0') ? key : _numpadVal + key;
        }
        document.getElementById('amount-tendered').value = _numpadVal;
        _updateNumpadDisplay();
        calcChange();
    }

    function numpadExact() {
        _numpadVal = state.total.toFixed(2);
        document.getElementById('amount-tendered').value = _numpadVal;
        _updateNumpadDisplay();
        calcChange();
    }

    function _updateNumpadDisplay() {
        const disp = document.getElementById('numpad-display');
        if (!disp) return;
        if (_tendCur === 'LBP') {
            const n = parseInt(_numpadVal || '0');
            disp.textContent = 'LBP ' + n.toLocaleString();
        } else {
            disp.textContent = CURRENCY + (_numpadVal || '0');
        }
    }

    function _applyNumpad(cashSelected) {
        const on = cashSelected && SHOW_NUMPAD;
        document.getElementById('numpad-display').classList.toggle('hidden', !on);
        document.getElementById('numpad-grid').classList.toggle('hidden', !on);
        document.getElementById('quick-amounts').classList.toggle('hidden', on);
        const inp = document.getElementById('amount-tendered');
        inp.classList.toggle('hidden', on);
        inp.readOnly = on;
    }

    function printReceipt() {
        if (state.lastReceiptId) window.open(`receipt.php?order_id=${state.lastReceiptId}`, '_blank', 'width=400,height=600');
    }

    function kickCashDrawer() {
        fetch('cash_drawer.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order_id: state.lastReceiptId })
        }).then(r => r.json()).then(d => {
            if (!d.success) showToast('Cash drawer: ' + (d.error || 'command failed'), 'warn');
        }).catch(() => {});
    }

    function toggleSearch() {
        state.searchOpen = !state.searchOpen;
        const bar = document.getElementById('search-bar');
        if (state.searchOpen) { bar.classList.remove('hidden'); document.getElementById('search-input').focus(); }
        else { bar.classList.add('hidden'); renderGrid(state.allItems); }
    }

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    // ── Init ─────────────────────────────────────────────────────────────────
    (async () => { await Promise.all([loadCategories(), checkExistingOrder()]); })();

    return { loadMenuItems, addItem, updateQty, removeItem, cancelOrder,
             openCheckout, closeCheckout, calcChange, setTendered, setPayment, setCurrency,
             processCheckout, numpad, numpadExact,
             printReceipt, kickCashDrawer, toggleSearch, filterItems };
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
