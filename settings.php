<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/auth.php';
requireAdmin();

$db  = Database::getInstance();
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $section = $_POST['section'] ?? '';

    if ($section === 'tax') {
        $taxRate = $_POST['tax_rate'] ?? '';
        if ($taxRate === '' || !is_numeric($taxRate)) {
            $err = 'Tax rate must be a number (e.g. 0, 5, 11).';
        } else {
            $taxRate = max(0, min(100, (float)$taxRate));
            Settings::set('tax_rate', (string)$taxRate);
            $msg = 'Tax rate saved.';
        }
    }

    if ($section === 'checkout') {
        Settings::set('auto_print_receipt', isset($_POST['auto_print_receipt']) ? '1' : '0');
        Settings::set('auto_open_drawer',   isset($_POST['auto_open_drawer'])   ? '1' : '0');
        Settings::set('show_numpad',        isset($_POST['show_numpad'])        ? '1' : '0');
        Settings::set('drawer_port',        trim($_POST['drawer_port'] ?? ''));
        $msg = 'Checkout settings saved.';
    }

    if ($section === 'currency') {
        $rate = trim($_POST['exchange_rate_usd_lbp'] ?? '');
        if ($rate === '' || !ctype_digit($rate)) {
            $err = 'Exchange rate must be a whole number (e.g. 89500).';
        } else {
            Settings::set('exchange_rate_usd_lbp', $rate);
            $msg = 'Exchange rate saved.';
        }
    }

    header('Location: settings.php' . ($msg ? '?msg='.urlencode($msg) : ($err ? '?err='.urlencode($err) : '')));
    exit;
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);
if (isset($_GET['err'])) $err = htmlspecialchars($_GET['err']);

$currentTax  = Settings::taxRate();
$autoPrint   = (int)Settings::get('auto_print_receipt', 0);
$autoDrawer  = (int)Settings::get('auto_open_drawer', 0);
$showNumpad  = (int)Settings::get('show_numpad', 1);
$lbpRate     = Settings::lbpRate();
$drawerPort  = Settings::get('drawer_port', '');

$activePage = 'settings';
$pageTitle  = 'Settings — ' . RESTAURANT_NAME;
include __DIR__ . '/includes/header.php';
?>

<!-- TOP BAR -->
<header class="bg-white border-b border-slate-200 px-6 py-3.5 flex items-center justify-between flex-shrink-0">
    <div>
        <h1 class="font-bold text-lg text-slate-800">Settings</h1>
        <p class="text-xs text-slate-400">Admin configuration</p>
    </div>
</header>

<!-- MAIN -->
<div class="flex-1 overflow-y-auto p-5 space-y-5 max-w-2xl">

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

    <!-- ── Tax ───────────────────────────────────────────────────────────── -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:#f2fce0;">
                <i class="fa-solid fa-percent text-sm" style="color:#436800;"></i>
            </div>
            <div>
                <h2 class="font-bold text-slate-800 text-sm">Taxation</h2>
                <p class="text-xs text-slate-400">Applied to every new order at creation time</p>
            </div>
        </div>
        <form method="POST" class="px-6 py-5 space-y-4">
            <input type="hidden" name="section" value="tax">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-2">Tax Rate (%)</label>
                <div class="flex items-center gap-3">
                    <div class="relative">
                        <input type="number" name="tax_rate"
                               value="<?= htmlspecialchars($currentTax) ?>"
                               min="0" max="100" step="0.01"
                               class="border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none w-36 pr-8"
                               placeholder="0">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm font-medium">%</span>
                    </div>
                    <p class="text-xs text-slate-400">Set to <strong>0</strong> to disable tax entirely.</p>
                </div>
            </div>

            <!-- Quick presets -->
            <div>
                <p class="text-xs font-semibold text-slate-400 mb-2">Quick presets</p>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ([0, 5, 7, 10, 11, 15, 20] as $preset): ?>
                    <button type="button"
                            onclick="document.querySelector('[name=tax_rate]').value = <?= $preset ?>"
                            class="px-3 py-1 text-xs rounded-lg border transition font-medium
                                   <?= $currentTax == $preset
                                       ? 'border-green-400 text-green-700'
                                       : 'border-slate-200 text-slate-500 hover:border-slate-300' ?>"
                            style="<?= $currentTax == $preset ? 'background:#f2fce0;' : '' ?>">
                        <?= $preset ?>%
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="pt-1 flex items-center gap-3">
                <button type="submit"
                        class="px-6 py-2.5 rounded-xl text-black text-sm font-black transition"
                        style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
                    Save Tax Rate
                </button>
                <?php if ($currentTax > 0): ?>
                <p class="text-xs text-slate-400">
                    Currently <strong><?= rtrim(rtrim(number_format($currentTax, 2), '0'), '.') ?>%</strong> — applied to all new orders.
                </p>
                <?php else: ?>
                <p class="text-xs text-slate-400">Currently <strong>disabled</strong> (0%).</p>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ── Note about existing orders ───────────────────────────────────── -->
    <p class="text-xs text-slate-400 px-1">
        <i class="fa-solid fa-circle-info mr-1"></i>
        Changing the tax rate only affects <strong>new orders</strong>. Existing orders keep the tax rate they were created with.
    </p>

    <!-- ── Checkout Automation ───────────────────────────────────────────── -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:#f2fce0;">
                <i class="fa-solid fa-bolt text-sm" style="color:#436800;"></i>
            </div>
            <div>
                <h2 class="font-bold text-slate-800 text-sm">Checkout Automation</h2>
                <p class="text-xs text-slate-400">Automatic actions triggered after each payment</p>
            </div>
        </div>
        <form method="POST" class="px-6 py-5 space-y-5">
            <input type="hidden" name="section" value="checkout">

            <!-- Auto-print receipt -->
            <div class="flex items-center justify-between gap-4">
                <div class="flex-1">
                    <p class="text-sm font-semibold text-slate-700">Auto-print receipt</p>
                    <p class="text-xs text-slate-400 mt-0.5">Opens the receipt window automatically after every checkout</p>
                </div>
                <button type="button" id="tb-auto-print" onclick="toggleBtn(this)"
                        style="position:relative;width:44px;height:24px;border-radius:12px;border:none;cursor:pointer;transition:background .2s;flex-shrink:0;background:<?= $autoPrint ? '#76B900' : '#e2e8f0' ?>">
                    <span style="position:absolute;top:2px;width:20px;height:20px;background:white;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:left .2s;left:<?= $autoPrint ? '22px' : '2px' ?>"></span>
                </button>
                <input type="hidden" name="auto_print_receipt" id="inp-auto-print" value="<?= $autoPrint ?>">
            </div>

            <!-- Show numpad -->
            <div class="flex items-center justify-between gap-4 pt-1 border-t border-slate-50">
                <div class="flex-1">
                    <p class="text-sm font-semibold text-slate-700">Show numpad on checkout</p>
                    <p class="text-xs text-slate-400 mt-0.5">Displays a large touch numpad for entering cash amounts</p>
                </div>
                <button type="button" id="tb-numpad" onclick="toggleBtn(this)"
                        style="position:relative;width:44px;height:24px;border-radius:12px;border:none;cursor:pointer;transition:background .2s;flex-shrink:0;background:<?= $showNumpad ? '#76B900' : '#e2e8f0' ?>">
                    <span style="position:absolute;top:2px;width:20px;height:20px;background:white;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:left .2s;left:<?= $showNumpad ? '22px' : '2px' ?>"></span>
                </button>
                <input type="hidden" name="show_numpad" id="inp-show-numpad" value="<?= $showNumpad ?>">
            </div>

            <!-- Auto-open cash drawer -->
            <div class="flex items-center justify-between gap-4 pt-1 border-t border-slate-50">
                <div class="flex-1">
                    <p class="text-sm font-semibold text-slate-700">Auto-open cash drawer</p>
                    <p class="text-xs text-slate-400 mt-0.5">Sends an ESC/POS drawer-kick command to your receipt printer</p>
                </div>
                <button type="button" id="tb-auto-drawer" onclick="toggleBtn(this)"
                        style="position:relative;width:44px;height:24px;border-radius:12px;border:none;cursor:pointer;transition:background .2s;flex-shrink:0;background:<?= $autoDrawer ? '#76B900' : '#e2e8f0' ?>">
                    <span style="position:absolute;top:2px;width:20px;height:20px;background:white;border-radius:50%;box-shadow:0 1px 3px rgba(0,0,0,.2);transition:left .2s;left:<?= $autoDrawer ? '22px' : '2px' ?>"></span>
                </button>
                <input type="hidden" name="auto_open_drawer" id="inp-auto-drawer" value="<?= $autoDrawer ?>">
            </div>

            <!-- Drawer printer port -->
            <div id="drawer-port-row" class="pt-1 <?= $autoDrawer ? '' : 'opacity-40 pointer-events-none' ?>">
                <label class="block text-xs font-semibold text-slate-500 mb-1.5">Printer port / path <span class="font-normal">(required for drawer)</span></label>
                <input type="text" name="drawer_port" value="<?= htmlspecialchars($drawerPort) ?>"
                       placeholder="e.g.  LPT1:  or  \\server\ReceiptPrinter"
                       class="border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none w-full">
                <p class="text-xs text-slate-400 mt-1.5">
                    <i class="fa-solid fa-circle-info mr-1"></i>
                    The cash drawer must be connected to the receipt printer's drawer port (RJ11 cable).
                    On most USB receipt printers the port name is the Windows shared printer path.
                </p>
            </div>

            <div class="pt-1">
                <button type="submit"
                        class="px-6 py-2.5 rounded-xl text-black text-sm font-black transition"
                        style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
                    Save Checkout Settings
                </button>
            </div>
        </form>
    </div>

</div>

<script>
function toggleBtn(btn) {
    const inp = btn.nextElementSibling;
    const isOn = inp.value === '1';
    inp.value = isOn ? '0' : '1';
    btn.style.background = isOn ? '#e2e8f0' : '#76B900';
    btn.querySelector('span').style.left = isOn ? '2px' : '22px';
    // Show/hide drawer port row when toggling the drawer button
    if (btn.id === 'tb-auto-drawer') {
        const row = document.getElementById('drawer-port-row');
        row.classList.toggle('opacity-40', isOn);
        row.classList.toggle('pointer-events-none', isOn);
    }
}
</script>

    <!-- ── Exchange Rate (USD / LBP) ─────────────────────────────────────── -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center" style="background:#f2fce0;">
                <i class="fa-solid fa-coins text-sm" style="color:#436800;"></i>
            </div>
            <div>
                <h2 class="font-bold text-slate-800 text-sm">USD / LBP Exchange Rate</h2>
                <p class="text-xs text-slate-400">Used to display prices in Lebanese Pounds alongside USD</p>
            </div>
        </div>
        <form method="POST" class="px-6 py-5 space-y-4">
            <input type="hidden" name="section" value="currency">
            <div>
                <label class="block text-xs font-semibold text-slate-500 mb-2">1 USD = X LBP</label>
                <div class="flex items-center gap-3">
                    <input type="text" name="exchange_rate_usd_lbp"
                           value="<?= htmlspecialchars($lbpRate ?: '') ?>"
                           placeholder="e.g. 89500"
                           class="border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none w-40">
                    <p class="text-xs text-slate-400">
                        <?php if ($lbpRate > 0): ?>
                            Currently <strong>1 USD = <?= number_format($lbpRate) ?> LBP</strong>
                        <?php else: ?>
                            Set to <strong>0</strong> or leave blank to disable LBP display.
                        <?php endif; ?>
                    </p>
                </div>
                <!-- Quick presets -->
                <div class="mt-3">
                    <p class="text-xs font-semibold text-slate-400 mb-2">Quick presets</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ([0, 89500, 90000, 91000, 95000, 100000] as $preset): ?>
                        <button type="button"
                                onclick="document.querySelector('[name=exchange_rate_usd_lbp]').value = '<?= $preset ?>'"
                                class="px-3 py-1 text-xs rounded-lg border transition font-medium
                                       <?= $lbpRate == $preset ? 'border-green-400 text-green-700' : 'border-slate-200 text-slate-500 hover:border-slate-300' ?>"
                                style="<?= $lbpRate == $preset ? 'background:#f2fce0;' : '' ?>">
                            <?= $preset === 0 ? 'Off' : number_format($preset) ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php if ($lbpRate > 0): ?>
            <div class="bg-slate-50 rounded-xl px-4 py-3 text-sm text-slate-600 flex items-center gap-2">
                <i class="fa-solid fa-circle-info text-slate-400"></i>
                Example: $10.00 = <strong>LBP <?= number_format(10 * $lbpRate) ?></strong>
            </div>
            <?php endif; ?>
            <div class="pt-1">
                <button type="submit"
                        class="px-6 py-2.5 rounded-xl text-black text-sm font-black transition"
                        style="background:#76B900;" onmouseover="this.style.background='#8ecf00'" onmouseout="this.style.background='#76B900'">
                    Save Exchange Rate
                </button>
            </div>
        </form>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
