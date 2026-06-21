<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/auth.php';
requireAuth();

$db       = Database::getInstance();
$dateFrom = $_GET['from'] ?? date('Y-m-01');   // first of current month
$dateTo   = $_GET['to']   ?? date('Y-m-d');
$tab      = $_GET['tab']  ?? 'overview';

// ── Revenue ────────────────────────────────────────────────────────────────────
$revenue = (float)$db->fetchScalar(
    "SELECT COALESCE(SUM(total),0) FROM orders
      WHERE status='paid' AND DATE(created_at) BETWEEN ? AND ?",
    [$dateFrom, $dateTo]
);
$orderCount = (int)$db->fetchScalar(
    "SELECT COUNT(*) FROM orders
      WHERE status='paid' AND DATE(created_at) BETWEEN ? AND ?",
    [$dateFrom, $dateTo]
);
$avgOrder = $orderCount ? round($revenue / $orderCount, 2) : 0;

// ── COGS via recipes ──────────────────────────────────────────────────────────
// Use a derived table for per-item cost to avoid correlated subquery inside aggregate
$cogs = (float)$db->fetchScalar(
    "SELECT COALESCE(SUM(oi.quantity * COALESCE(ic.unit_cost, 0)), 0)
       FROM order_items oi
       JOIN orders o ON o.id = oi.order_id
                    AND o.status = 'paid'
                    AND DATE(o.created_at) BETWEEN ? AND ?
       LEFT JOIN (
           SELECT mii.menu_item_id,
                  SUM(mii.quantity_needed * inv.cost_per_unit) AS unit_cost
             FROM menu_item_ingredients mii
             JOIN inventory inv ON inv.id = mii.inventory_id
            GROUP BY mii.menu_item_id
       ) ic ON ic.menu_item_id = oi.menu_item_id",
    [$dateFrom, $dateTo]
);
$hasRecipes = (int)$db->fetchScalar(
    "SELECT COUNT(*) FROM menu_item_ingredients"
) > 0;

$grossProfit = $revenue - $cogs;
// Margin is only meaningful when we actually have cost data
$margin = ($revenue > 0 && $cogs > 0) ? round($grossProfit / $revenue * 100, 1) : null;

// ── Purchase spend ─────────────────────────────────────────────────────────────
$purchaseSpend = (float)$db->fetchScalar(
    "SELECT COALESCE(SUM(total_cost),0) FROM purchase_log
      WHERE DATE(created_at) BETWEEN ? AND ?",
    [$dateFrom, $dateTo]
);

// ── Top selling items ─────────────────────────────────────────────────────────
// NULL cost = no recipe linked; 0 cost = recipe exists but cost_per_unit not set
$topItems = $db->fetchAll(
    "SELECT oi.item_name,
            SUM(oi.quantity) AS qty_sold,
            SUM(oi.subtotal) AS revenue,
            CASE WHEN MAX(ic.menu_item_id) IS NOT NULL
                 THEN SUM(oi.quantity * COALESCE(ic.unit_cost, 0))
                 ELSE NULL END AS cost
       FROM order_items oi
       JOIN orders o ON o.id = oi.order_id
                    AND o.status = 'paid'
                    AND DATE(o.created_at) BETWEEN ? AND ?
       LEFT JOIN (
           SELECT mii.menu_item_id,
                  SUM(mii.quantity_needed * inv.cost_per_unit) AS unit_cost
             FROM menu_item_ingredients mii
             JOIN inventory inv ON inv.id = mii.inventory_id
            GROUP BY mii.menu_item_id
       ) ic ON ic.menu_item_id = oi.menu_item_id
      GROUP BY oi.menu_item_id, oi.item_name
      ORDER BY revenue DESC
      LIMIT 20",
    [$dateFrom, $dateTo]
);

// ── Daily revenue (for chart data) ────────────────────────────────────────────
$dailyRevenue = $db->fetchAll(
    "SELECT DATE(created_at) AS day, COALESCE(SUM(total),0) AS rev, COUNT(*) AS orders
       FROM orders
      WHERE status='paid' AND DATE(created_at) BETWEEN ? AND ?
      GROUP BY DATE(created_at)
      ORDER BY day ASC",
    [$dateFrom, $dateTo]
);

// ── Purchase log ─────────────────────────────────────────────────────────────
$purchases = $db->fetchAll(
    "SELECT pl.*, i.name AS item_name, i.unit, u.name AS user_name
       FROM purchase_log pl
       JOIN inventory i ON i.id = pl.inventory_id
       LEFT JOIN users u ON u.id = pl.user_id
      WHERE DATE(pl.created_at) BETWEEN ? AND ?
      ORDER BY pl.created_at DESC
      LIMIT 200",
    [$dateFrom, $dateTo]
);

$activePage = 'reports';
$pageTitle  = 'Reports — ' . RESTAURANT_NAME;
include __DIR__ . '/includes/header.php';
?>

<!-- TOP BAR -->
<header class="bg-white border-b border-slate-200 px-6 py-3.5 flex items-center justify-between flex-shrink-0">
    <div>
        <h1 class="font-bold text-lg text-slate-800">Reports</h1>
        <p class="text-xs text-slate-400"><?= $dateFrom === $dateTo ? $dateFrom : "$dateFrom → $dateTo" ?></p>
    </div>
    <form method="GET" class="flex items-end gap-2">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <div>
            <label class="block text-xs font-semibold text-slate-400 mb-1">From</label>
            <input type="date" name="from" value="<?= $dateFrom ?>"
                   class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm outline-none">
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-400 mb-1">To</label>
            <input type="date" name="to" value="<?= $dateTo ?>"
                   class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm outline-none">
        </div>
        <button type="submit" class="text-black text-sm px-4 py-2 rounded-lg font-bold transition"
                style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
            Apply
        </button>
    </form>
</header>

<!-- MAIN -->
<div class="flex-1 overflow-y-auto p-5 space-y-5">

    <!-- KPI Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <?php
        $kpis = [
            ['label'=>'Revenue',      'val'=> CURRENCY_SYMBOL.number_format($revenue,2),                                     'icon'=>'fa-dollar-sign',  'bg'=>'#dcfce7','ic'=>'#166534'],
            ['label'=>'Gross Profit', 'val'=> $cogs>0 ? CURRENCY_SYMBOL.number_format($grossProfit,2) : '—',                'icon'=>'fa-chart-line',   'bg'=>($cogs>0&&$grossProfit>=0)?'#f0fdf4':($cogs>0?'#fff1f2':'#f8fafc'),'ic'=>($cogs>0&&$grossProfit>=0)?'#166834':($cogs>0?'#991b1b':'#94a3b8')],
            ['label'=>'Margin',       'val'=> $margin !== null ? $margin.'%' : '—',                                          'icon'=>'fa-percent',      'bg'=>'#f2fce0','ic'=>$margin!==null?'#436800':'#94a3b8'],
            ['label'=>'Purchases',    'val'=> CURRENCY_SYMBOL.number_format($purchaseSpend,2),                               'icon'=>'fa-cart-flatbed', 'bg'=>'#fef9c3','ic'=>'#713f12'],
        ];
        foreach ($kpis as $k): ?>
        <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-100">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-semibold text-slate-500"><?= $k['label'] ?></span>
                <div class="w-8 h-8 rounded-xl flex items-center justify-center" style="background:<?= $k['bg'] ?>;">
                    <i class="fa-solid <?= $k['icon'] ?> text-sm" style="color:<?= $k['ic'] ?>;"></i>
                </div>
            </div>
            <p class="text-2xl font-black text-slate-800"><?= $k['val'] ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Secondary stats -->
    <div class="grid grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100">
            <p class="text-xs font-semibold text-slate-400 mb-1">Paid Orders</p>
            <p class="text-xl font-black text-slate-800"><?= $orderCount ?></p>
        </div>
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100">
            <p class="text-xs font-semibold text-slate-400 mb-1">Avg Order Value</p>
            <p class="text-xl font-black text-slate-800"><?= CURRENCY_SYMBOL ?><?= number_format($avgOrder,2) ?></p>
        </div>
        <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100">
            <p class="text-xs font-semibold text-slate-400 mb-1">Est. COGS (recipes)</p>
            <p class="text-xl font-black text-slate-800"><?= CURRENCY_SYMBOL ?><?= number_format($cogs,2) ?></p>
            <p class="text-xs text-slate-400 mt-0.5">Items with recipes only</p>
        </div>
    </div>

    <?php if (!$hasRecipes): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl px-5 py-4 flex items-start gap-3">
        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
        <div>
            <p class="text-sm font-semibold text-amber-800">Cost data missing — margins cannot be calculated</p>
            <p class="text-xs text-amber-700 mt-0.5">No items are linked to ingredient costs. When you add inventory items to the menu using the "Also add this item to the menu" option, the cost link is created automatically. For existing menu items, link them via <a href="menu.php" class="underline font-medium">Menu → Recipes</a>.</p>
        </div>
    </div>
    <?php elseif ($cogs == 0 && $revenue > 0): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl px-5 py-4 flex items-start gap-3">
        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
        <div>
            <p class="text-sm font-semibold text-amber-800">Recipe links exist but ingredient costs are $0</p>
            <p class="text-xs text-amber-700 mt-0.5">Set a <strong>Cost / Unit</strong> on your inventory items so the system can calculate COGS and profit margins.</p>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="flex border-b border-slate-200 gap-0">
        <?php foreach (['overview'=>'Sales Breakdown','purchases'=>'Purchase Log','daily'=>'Daily Sales'] as $t=>$label): ?>
        <a href="?tab=<?= $t ?>&from=<?= $dateFrom ?>&to=<?= $dateTo ?>"
           class="px-5 py-2.5 text-sm font-medium border-b-2 transition <?= $tab===$t ? 'border-slate-800 text-slate-800' : 'border-transparent text-slate-500 hover:text-slate-700' ?>">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($tab === 'overview'): ?>
    <!-- SALES BREAKDOWN -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
            <h3 class="font-semibold text-slate-700 text-sm">Top Selling Items</h3>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Item</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Qty Sold</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Revenue</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Est. Cost</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Profit</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Margin</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if (empty($topItems)): ?>
                <tr><td colspan="6" class="text-center py-10 text-slate-400">No sales in this period.</td></tr>
                <?php else: ?>
                <?php foreach ($topItems as $item):
                    $hasCost = $item['cost'] !== null;
                    $cost    = $hasCost ? (float)$item['cost'] : 0.0;
                    $rev     = (float)$item['revenue'];
                    $profit  = $hasCost ? $rev - $cost : null;
                    $mgn     = ($hasCost && $rev > 0) ? round(($rev - $cost) / $rev * 100, 1) : null;
                    $mgnClr  = $mgn === null ? '#94a3b8' : ($mgn >= 50 ? '#166534' : ($mgn >= 20 ? '#436800' : '#991b1b'));
                ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3 font-medium text-slate-800"><?= htmlspecialchars($item['item_name']) ?></td>
                    <td class="px-4 py-3 text-right text-slate-600"><?= $item['qty_sold'] ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-slate-700"><?= CURRENCY_SYMBOL ?><?= number_format($rev, 2) ?></td>
                    <td class="px-4 py-3 text-right text-slate-500">
                        <?php if ($hasCost): ?>
                            <?= CURRENCY_SYMBOL ?><?= number_format($cost, 2) ?>
                        <?php else: ?>
                            <span class="text-slate-300" title="No recipe linked">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-semibold">
                        <?php if ($profit !== null): ?>
                            <span class="<?= $profit >= 0 ? 'text-emerald-600' : 'text-rose-500' ?>">
                                <?= CURRENCY_SYMBOL ?><?= number_format($profit, 2) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-slate-300">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <?php if ($mgn !== null): ?>
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full" style="background:<?= $mgnClr ?>22; color:<?= $mgnClr ?>;">
                            <?= $mgn ?>%
                        </span>
                        <?php else: ?>
                        <span class="text-slate-300 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="text-xs text-slate-400">* Cost and margin are estimated from linked recipes only. Items without a recipe show —.</p>

    <?php elseif ($tab === 'purchases'): ?>
    <!-- PURCHASE LOG -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
            <h3 class="font-semibold text-slate-700 text-sm">Purchase Log</h3>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Item</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Qty</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Cost/Unit</th>
                    <th class="text-right px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Total Cost</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Notes</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">By</th>
                    <th class="text-left px-4 py-3 text-xs font-semibold text-slate-500 uppercase tracking-wide">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if (empty($purchases)): ?>
                <tr><td colspan="7" class="text-center py-10 text-slate-400">No purchases recorded in this period.</td></tr>
                <?php else: ?>
                <?php foreach ($purchases as $p): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3 font-medium text-slate-800"><?= htmlspecialchars($p['item_name']) ?></td>
                    <td class="px-4 py-3 text-right text-slate-600">
                        <?php if ($p['qty_boxes']): ?>
                            <?= number_format($p['qty_boxes'],2) ?> boxes
                            <p class="text-xs text-slate-400"><?= number_format($p['qty_units'],2) ?> units</p>
                        <?php else: ?>
                            <?= number_format($p['qty_units'],3) ?> <?= htmlspecialchars($p['unit']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right text-slate-500"><?= CURRENCY_SYMBOL ?><?= number_format($p['cost_per_unit'],4) ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-slate-700"><?= CURRENCY_SYMBOL ?><?= number_format($p['total_cost'],2) ?></td>
                    <td class="px-4 py-3 text-slate-500 text-xs"><?= htmlspecialchars($p['notes'] ?? '') ?></td>
                    <td class="px-4 py-3 text-slate-500 text-xs"><?= htmlspecialchars($p['user_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-slate-500 text-xs"><?= date('d M Y H:i', strtotime($p['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($purchases)): ?>
            <tfoot class="bg-slate-50 border-t border-slate-200">
                <tr>
                    <td colspan="3" class="px-5 py-3 text-xs font-semibold text-slate-500">Total Purchase Spend</td>
                    <td class="px-4 py-3 text-right font-black text-slate-800"><?= CURRENCY_SYMBOL ?><?= number_format($purchaseSpend,2) ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

    <?php elseif ($tab === 'daily'): ?>
    <!-- DAILY SALES -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100">
            <h3 class="font-semibold text-slate-700 text-sm">Daily Sales Breakdown</h3>
        </div>

        <?php if (!empty($dailyRevenue)): ?>
        <!-- Bar chart -->
        <div class="px-5 pt-4 pb-2">
            <?php
            $maxRev = max(array_column($dailyRevenue, 'rev')) ?: 1;
            foreach ($dailyRevenue as $day):
                $pct = round($day['rev'] / $maxRev * 100);
            ?>
            <div class="flex items-center gap-3 mb-2">
                <span class="text-xs text-slate-500 w-20 flex-shrink-0"><?= date('d M', strtotime($day['day'])) ?></span>
                <div class="flex-1 bg-slate-100 rounded-full h-5 overflow-hidden">
                    <div class="h-full rounded-full flex items-center pl-2" style="width:<?= max($pct,2) ?>%; background:#76B900;">
                        <?php if ($pct > 15): ?>
                        <span class="text-xs font-bold text-black"><?= CURRENCY_SYMBOL ?><?= number_format($day['rev'],0) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="text-xs font-semibold text-slate-700 w-24 text-right flex-shrink-0">
                    <?= CURRENCY_SYMBOL ?><?= number_format($day['rev'],2) ?>
                    <span class="text-slate-400 font-normal">(<?= $day['orders'] ?>)</span>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-center py-10 text-slate-400 text-sm">No sales data in this period.</p>
        <?php endif; ?>

        <table class="w-full text-sm border-t border-slate-100">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-2 text-xs font-semibold text-slate-500 uppercase">Date</th>
                    <th class="text-right px-4 py-2 text-xs font-semibold text-slate-500 uppercase">Orders</th>
                    <th class="text-right px-4 py-2 text-xs font-semibold text-slate-500 uppercase">Revenue</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if (empty($dailyRevenue)): ?>
                <tr><td colspan="3" class="text-center py-6 text-slate-400">—</td></tr>
                <?php else: ?>
                <?php foreach ($dailyRevenue as $day): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-2.5 text-slate-700"><?= date('l, d M Y', strtotime($day['day'])) ?></td>
                    <td class="px-4 py-2.5 text-right text-slate-500"><?= $day['orders'] ?></td>
                    <td class="px-4 py-2.5 text-right font-bold text-slate-800"><?= CURRENCY_SYMBOL ?><?= number_format($day['rev'],2) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="bg-slate-50 border-t border-slate-200">
                    <td class="px-5 py-2.5 font-bold text-slate-700">Total</td>
                    <td class="px-4 py-2.5 text-right font-bold text-slate-700"><?= $orderCount ?></td>
                    <td class="px-4 py-2.5 text-right font-black text-slate-800"><?= CURRENCY_SYMBOL ?><?= number_format($revenue,2) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
