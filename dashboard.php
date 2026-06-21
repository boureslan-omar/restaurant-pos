<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$activePage = 'dashboard';
$pageTitle  = 'Floor View — ' . RESTAURANT_NAME;
include __DIR__ . '/includes/header.php';
?>

<!-- TOP BAR -->
<header class="bg-white border-b border-slate-200 px-6 py-3.5 flex items-center justify-between flex-shrink-0">
    <div>
        <h1 class="font-bold text-lg text-slate-800">Floor View — Main Hall</h1>
        <p class="text-xs text-slate-400" id="last-updated">Loading…</p>
    </div>
    <div class="flex items-center gap-2">
        <a href="pos.php?type=takeaway"
           class="inline-flex items-center gap-2 text-black text-sm font-bold px-4 py-2 rounded-xl transition"
           style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
            <i class="fa-solid fa-bag-shopping"></i> Takeout
        </a>
        <button onclick="loadDashboard()" class="p-2 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition">
            <i class="fa-solid fa-arrows-rotate" id="refresh-icon"></i>
        </button>
    </div>
</header>

<!-- MAIN -->
<div class="flex-1 overflow-y-auto p-5 space-y-5">

    <!-- KPI row -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4" id="kpi-row">
        <?php for($i=0;$i<4;$i++): ?><div class="bg-white rounded-2xl p-5 h-24 animate-pulse shadow-sm border border-slate-100"></div><?php endfor; ?>
    </div>

    <!-- Floor + sidebar -->
    <div class="flex gap-5 items-start">

        <!-- Table grid -->
        <div class="flex-1">
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-100">
                <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3" id="floor-grid">
                    <?php for($i=0;$i<10;$i++): ?><div class="h-24 rounded-xl animate-pulse bg-slate-100"></div><?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- Right sidebar -->
        <div class="w-68 flex-shrink-0 space-y-4" style="width:270px;">
            <!-- Low stock -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="font-semibold text-sm text-slate-700 flex items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500"></i> Low Stock
                    </h3>
                    <a href="inventory.php" class="text-xs hover:underline" style="color:#76B900;">Manage</a>
                </div>
                <div id="low-stock-list" class="max-h-52 overflow-y-auto">
                    <p class="px-4 py-3 text-xs text-slate-400">Loading…</p>
                </div>
            </div>
            <!-- Recent orders -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="font-semibold text-sm text-slate-700 flex items-center gap-2">
                        <i class="fa-solid fa-clock-rotate-left" style="color:#76B900;"></i> Today's Orders
                    </h3>
                    <a href="orders.php" class="text-xs hover:underline" style="color:#76B900;">View all</a>
                </div>
                <div id="recent-orders-list" class="max-h-64 overflow-y-auto">
                    <p class="px-4 py-3 text-xs text-slate-400">Loading…</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Legend -->
<div class="bg-white border-t border-slate-200 px-6 py-2.5 flex items-center gap-6 text-xs text-slate-500 flex-shrink-0">
    <span class="font-semibold text-slate-600">Status:</span>
    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-emerald-400 inline-block"></span>Open</span>
    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-rose-400 inline-block"></span>Occupied</span>
    <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded-full bg-amber-400 inline-block"></span>Reserved</span>
</div>

<!-- TABLE ACTION MODAL (open table) -->
<div id="table-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xs overflow-hidden">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-slate-800" id="table-modal-title">Table T1</h2>
            <button onclick="closeTableModal()" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <div class="p-5 space-y-2">
            <button onclick="startOrderForTable()" class="w-full py-3 rounded-xl text-black font-black text-sm transition"
                    style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
                <i class="fa-solid fa-cash-register mr-2"></i>Start New Order
            </button>
            <button onclick="openReserveModal()" class="w-full py-3 rounded-xl border-2 border-amber-300 text-amber-700 font-bold text-sm hover:bg-amber-50 transition">
                <i class="fa-solid fa-calendar-plus mr-2"></i>Reserve This Table
            </button>
        </div>
    </div>
</div>

<!-- RESERVATION MODAL -->
<div id="reserve-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-slate-800">Reserve Table <span id="reserve-table-label"></span></h2>
            <button onclick="closeReserveModal()" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
        </div>
        <form onsubmit="saveReservation(event)" class="px-6 py-4 space-y-3">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Client Name *</label>
                <input type="text" id="res-client" required placeholder="e.g. John Smith"
                       class="w-full border-2 border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Date *</label>
                <input type="date" id="res-date" required
                       class="w-full border-2 border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">From *</label>
                    <input type="time" id="res-from" required
                           class="w-full border-2 border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">To *</label>
                    <input type="time" id="res-to" required
                           class="w-full border-2 border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Notes</label>
                <input type="text" id="res-notes" placeholder="Optional notes"
                       class="w-full border-2 border-slate-200 rounded-xl px-3 py-2 text-sm outline-none">
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="closeReserveModal()"
                        class="flex-1 py-2.5 border-2 border-slate-200 rounded-xl text-slate-600 text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                <button type="submit"
                        class="flex-1 py-2.5 rounded-xl text-black text-sm font-black transition" style="background:#76B900;">
                    Confirm Reservation
                </button>
            </div>
        </form>
    </div>
</div>

<!-- RESERVATION DETAIL MODAL (reserved tables) -->
<div id="res-detail-modal" class="hidden fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xs overflow-hidden">
        <div class="px-5 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-slate-800">Reservation Details</h2>
            <button onclick="document.getElementById('res-detail-modal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-2xl">&times;</button>
        </div>
        <div id="res-detail-body" class="px-5 py-4 space-y-2 text-sm"></div>
        <div class="px-5 pb-4 flex gap-2">
            <button id="res-start-order-btn" class="flex-1 py-2.5 rounded-xl text-black font-black text-sm" style="background:#76B900;">
                <i class="fa-solid fa-cash-register mr-1"></i> Start Order
            </button>
            <button id="res-cancel-btn" class="flex-1 py-2.5 rounded-xl border-2 border-rose-200 text-rose-500 font-semibold text-sm hover:bg-rose-50 transition">
                <i class="fa-solid fa-ban mr-1"></i> Cancel Res.
            </button>
        </div>
    </div>
</div>

<script>
const CURRENCY = '<?= CURRENCY_SYMBOL ?>';
let _selectedTableId = null;
let _selectedTableNum = '';
let _selectedReservationId = null;

// ── Status styles ────────────────────────────────────────────────────────────
const ST = {
    open:     { border:'#86efac', bg:'#f0fdf4', dot:'#4ade80', badge:'#dcfce7', badgeTxt:'#166534', label:'Open' },
    occupied: { border:'#fca5a5', bg:'#fff1f2', dot:'#f87171', badge:'#fee2e2', badgeTxt:'#991b1b', label:'Occupied' },
    reserved: { border:'#fcd34d', bg:'#fffbeb', dot:'#fbbf24', badge:'#fef9c3', badgeTxt:'#713f12', label:'Reserved' },
};

async function apiFetch(action, params = {}) {
    const r = await fetch(`api.php?action=${action}&${new URLSearchParams(params)}`);
    return r.json();
}
async function apiPost(action, body) {
    const r = await fetch(`api.php?action=${action}`, {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)
    });
    return r.json();
}

// ── KPIs ─────────────────────────────────────────────────────────────────────
function renderKPIs(d) {
    document.getElementById('kpi-row').innerHTML = [
        { icon:'fa-dollar-sign',  clr:'#dcfce7', iclr:'#166534', label:"Today's Sales",   val: CURRENCY + parseFloat(d.today_sales||0).toFixed(2) },
        { icon:'fa-receipt',      clr:'#f2fce0', iclr:'#436800', label:'Orders Today',    val: d.today_orders||0 },
        { icon:'fa-chair',        clr:'#fee2e2', iclr:'#991b1b', label:'Tables Occupied', val:`${d.occupied_tables||0} / ${d.total_tables||0}` },
        { icon:'fa-triangle-exclamation', clr:'#fef9c3', iclr:'#713f12', label:'Low Stock',val: d.low_stock_count||0 },
    ].map(k => `
        <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-100 card-lift">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-slate-500">${k.label}</span>
                <div class="w-8 h-8 rounded-xl flex items-center justify-center" style="background:${k.clr};">
                    <i class="fa-solid ${k.icon} text-sm" style="color:${k.iclr};"></i>
                </div>
            </div>
            <p class="text-2xl font-black text-slate-800">${k.val}</p>
        </div>`).join('');
}

// ── Floor grid ───────────────────────────────────────────────────────────────
function renderFloor(tables) {
    const grid = document.getElementById('floor-grid');
    if (!tables.length) {
        grid.innerHTML = '<p class="col-span-full text-slate-400 text-sm text-center py-8">No tables found for Main Hall.</p>';
        return;
    }
    grid.innerHTML = tables.map(t => {
        const s = ST[t.status] || ST.open;
        return `
        <div onclick="onTableClick(${t.id},'${t.status}',${t.order_id||'null'},${t.reservation_id||'null'})"
             class="cursor-pointer rounded-xl p-3 border-2 select-none transition card-lift"
             style="background:${s.bg}; border-color:${s.border};">
            <div class="flex items-start justify-between mb-2">
                <span class="font-black text-slate-800 text-xl leading-none">${t.table_number}</span>
                <span class="w-2.5 h-2.5 rounded-full flex-shrink-0 mt-0.5 ${t.status==='occupied'?'animate-pulse':''}"
                      style="background:${s.dot};"></span>
            </div>
            <span class="text-xs font-semibold px-2 py-0.5 rounded-full"
                  style="background:${s.badge}; color:${s.badgeTxt};">${s.label}</span>
            ${t.order_total ? `<p class="text-xs font-bold mt-1.5 text-slate-600">${CURRENCY}${parseFloat(t.order_total).toFixed(2)}</p>` : ''}
            ${t.client_name ? `<p class="text-xs text-amber-700 font-semibold mt-1 truncate">${t.client_name}</p>
                               <p class="text-xs text-amber-600">${t.from_time||''} – ${t.to_time||''}</p>` : ''}
        </div>`;
    }).join('');
}

// ── Table click dispatcher ───────────────────────────────────────────────────
function onTableClick(tableId, status, orderId, reservationId) {
    _selectedTableId  = tableId;
    _selectedTableNum = document.querySelector(`[onclick*="onTableClick(${tableId},"]`)?.querySelector('.font-black')?.textContent || '';

    if (status === 'occupied' && orderId) {
        window.location = `pos.php?table_id=${tableId}&order_id=${orderId}`;
    } else if (status === 'reserved') {
        _selectedReservationId = reservationId;
        openReservationDetail(tableId, reservationId);
    } else {
        // open → show action modal
        document.getElementById('table-modal-title').textContent = `Table ${_selectedTableNum}`;
        document.getElementById('table-modal').classList.remove('hidden');
    }
}

function closeTableModal() { document.getElementById('table-modal').classList.add('hidden'); }

function startOrderForTable() {
    closeTableModal();
    window.location = `pos.php?table_id=${_selectedTableId}`;
}

// ── Reservation modal ────────────────────────────────────────────────────────
function openReserveModal() {
    closeTableModal();
    document.getElementById('reserve-table-label').textContent = _selectedTableNum;
    document.getElementById('res-client').value = '';
    document.getElementById('res-date').value   = new Date().toISOString().slice(0,10);
    document.getElementById('res-from').value   = '';
    document.getElementById('res-to').value     = '';
    document.getElementById('res-notes').value  = '';
    document.getElementById('reserve-modal').classList.remove('hidden');
}
function closeReserveModal() { document.getElementById('reserve-modal').classList.add('hidden'); }

async function saveReservation(e) {
    e.preventDefault();
    const res = await apiPost('add_reservation', {
        table_id:     _selectedTableId,
        client_name:  document.getElementById('res-client').value.trim(),
        reserved_date:document.getElementById('res-date').value,
        from_time:    document.getElementById('res-from').value,
        to_time:      document.getElementById('res-to').value,
        notes:        document.getElementById('res-notes').value.trim(),
    });
    if (res.success) {
        closeReserveModal();
        showToast('Table reserved!', 'success');
        loadDashboard();
    } else {
        showToast(res.error || 'Failed to reserve', 'error');
    }
}

// ── Reservation detail ───────────────────────────────────────────────────────
async function openReservationDetail(tableId, reservationId) {
    const res = await apiFetch('get_table_reservations', { table_id: tableId });
    const reservations = res.data || [];
    const r = reservations.find(x => x.id == reservationId) || reservations[0];
    if (!r) return;

    document.getElementById('res-detail-body').innerHTML = `
        <div class="space-y-2">
            <div class="flex justify-between"><span class="text-slate-500">Client</span><span class="font-bold text-slate-800">${r.client_name}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Date</span><span class="font-semibold">${r.reserved_date}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Time</span><span class="font-semibold">${r.from_time} – ${r.to_time}</span></div>
            ${r.notes ? `<div class="flex justify-between"><span class="text-slate-500">Notes</span><span class="text-slate-700">${r.notes}</span></div>` : ''}
        </div>`;

    document.getElementById('res-start-order-btn').onclick = () => {
        document.getElementById('res-detail-modal').classList.add('hidden');
        window.location = `pos.php?table_id=${tableId}`;
    };
    document.getElementById('res-cancel-btn').onclick = () => cancelReservation(r.id);
    document.getElementById('res-detail-modal').classList.remove('hidden');
}

async function cancelReservation(resId) {
    if (!confirm('Cancel this reservation?')) return;
    const res = await apiPost('cancel_reservation', { reservation_id: resId });
    if (res.success) {
        document.getElementById('res-detail-modal').classList.add('hidden');
        showToast('Reservation cancelled', 'warn');
        loadDashboard();
    } else {
        showToast(res.error || 'Failed', 'error');
    }
}

// ── Low stock ────────────────────────────────────────────────────────────────
function renderLowStock(items) {
    const el = document.getElementById('low-stock-list');
    if (!items.length) { el.innerHTML = '<p class="px-4 py-3 text-xs text-slate-400 italic">All stock levels OK</p>'; return; }
    el.innerHTML = items.map(i => {
        const pct = Math.min(100, Math.round(i.current_stock / i.min_alert_level * 100));
        const clr = pct <= 25 ? '#f87171' : '#fbbf24';
        return `<div class="px-4 py-2.5">
            <div class="flex justify-between text-xs mb-1">
                <span class="font-semibold text-slate-700 truncate">${i.name}</span>
                <span class="text-slate-400 ml-2">${parseFloat(i.current_stock).toFixed(1)} ${i.unit}</span>
            </div>
            <div class="h-1.5 bg-slate-100 rounded-full overflow-hidden">
                <div class="h-full rounded-full" style="width:${pct}%; background:${clr};"></div>
            </div>
        </div>`;
    }).join('');
}

// ── Recent orders ────────────────────────────────────────────────────────────
function renderRecentOrders(orders) {
    const el = document.getElementById('recent-orders-list');
    if (!orders.length) { el.innerHTML = '<p class="px-4 py-3 text-xs text-slate-400 italic">No orders today</p>'; return; }
    const clr = { pending:'#fef9c3 / #713f12', paid:'#dcfce7 / #166534', cancelled:'#f1f5f9 / #64748b' };
    el.innerHTML = orders.map(o => {
        const isPending = !['paid','cancelled'].includes(o.status);
        return `<div class="px-4 py-2.5 flex items-center gap-3 hover:bg-slate-50 cursor-pointer transition"
                     onclick="${isPending ? `window.location='pos.php?table_id=${o.table_id}&order_id=${o.id}'` : `window.location='orders.php'`}">
            <div class="flex-1 min-w-0">
                <p class="text-xs font-bold text-slate-700">#${o.id} · ${o.table_number||'Takeout'}</p>
                <p class="text-xs text-slate-400">${o.item_count} item${o.item_count!=1?'s':''} · ${CURRENCY}${parseFloat(o.total).toFixed(2)}</p>
            </div>
            <span class="text-xs px-2 py-0.5 rounded-full font-semibold
                ${o.status==='paid'?'bg-emerald-100 text-emerald-700':o.status==='cancelled'?'bg-slate-100 text-slate-500':'bg-yellow-100 text-yellow-700'}">
                ${o.status}
            </span>
        </div>`;
    }).join('');
}

// ── Load all ─────────────────────────────────────────────────────────────────
async function loadDashboard() {
    document.getElementById('refresh-icon').classList.add('animate-spin');
    try {
        const [stats, tables, stock, orders] = await Promise.all([
            apiFetch('dashboard_stats'),
            apiFetch('get_tables', { section: 'Main Hall' }),
            apiFetch('low_stock'),
            apiFetch('recent_orders'),
        ]);
        if (stats.success)  renderKPIs(stats.data);
        if (tables.success) renderFloor(tables.data);
        if (stock.success)  renderLowStock(stock.data);
        if (orders.success) renderRecentOrders(orders.data);
        document.getElementById('last-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();
    } catch(e) {
        showToast('Failed to load dashboard', 'error');
    } finally {
        document.getElementById('refresh-icon').classList.remove('animate-spin');
    }
}

loadDashboard();
setInterval(loadDashboard, 30000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
