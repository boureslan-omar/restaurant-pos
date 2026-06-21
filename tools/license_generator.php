<?php
/**
 * Web-based license generator — developer access only.
 * Change DEV_PASSWORD below before deploying.
 */
session_name('pos_dev_tool');
session_start();

define('DEV_PASSWORD', 'padel07dev'); // ← change this

// Fix OpenSSL on XAMPP Windows
if (PHP_OS_FAMILY === 'Windows' && empty(getenv('OPENSSL_CONF'))) {
    foreach (['C:/xampp/apache/conf/openssl.cnf', dirname(PHP_BINARY) . '/../apache/conf/openssl.cnf'] as $c) {
        if (file_exists($c)) { putenv('OPENSSL_CONF=' . realpath($c)); break; }
    }
}

$privFile = __DIR__ . '/private.pem';
$error    = '';
$license  = '';
$loggedIn = !empty($_SESSION['dev_auth']);

// Login
if (!$loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === DEV_PASSWORD) {
        $_SESSION['dev_auth'] = true;
        $loggedIn = true;
    } else {
        $error = 'Wrong password.';
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: license_generator.php');
    exit;
}

// Generate license
$genMachine = '';
$genExpiry  = '';
if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['machine_guid'])) {
    $genMachine = strtolower(trim($_POST['machine_guid'] ?? ''));
    $genExpiry  = trim($_POST['expiry'] ?? 'lifetime');

    if (!preg_match('/^[0-9a-f\-]{36}$/i', $genMachine)) {
        $error = 'Invalid Machine GUID format. Expected: xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx';
    } elseif ($genExpiry !== 'lifetime' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $genExpiry)) {
        $error = 'Invalid expiry format. Use YYYY-MM-DD or leave as "lifetime".';
    } elseif ($genExpiry !== 'lifetime' && strtotime($genExpiry) < time()) {
        $error = 'Expiry date is in the past.';
    } elseif (!file_exists($privFile)) {
        $error = 'Private key not found. Run php tools/generate_keys.php from CLI first.';
    } else {
        $privateKey = openssl_pkey_get_private((string)file_get_contents($privFile));
        if (!$privateKey) {
            $error = 'Could not load private key: ' . openssl_error_string();
        } else {
            $payload = $genMachine . '|' . $genExpiry;
            if (!openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                $error = 'Signing failed: ' . openssl_error_string();
            } else {
                $license = base64_encode($payload . '|' . $signature);
            }
        }
    }
}

// Download as file
if ($loggedIn && isset($_POST['download_content']) && $_POST['download_content']) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="license.lic"');
    header('Content-Length: ' . strlen($_POST['download_content']));
    echo $_POST['download_content'];
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>License Generator</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="min-h-screen bg-zinc-950 flex items-center justify-center p-6">
<div class="w-full max-w-lg">

    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl mb-4" style="background:#76B900;">
            <i class="fa-solid fa-key text-white text-xl"></i>
        </div>
        <h1 class="text-white text-2xl font-black">License Generator</h1>
        <p class="text-zinc-500 text-sm mt-1">Developer Tool — Padel07 POS</p>
    </div>

<?php if (!$loggedIn): ?>
    <!-- Login form -->
    <div class="rounded-2xl p-6" style="background:#18181b;">
        <p class="text-zinc-400 text-sm mb-4">Enter developer password to continue.</p>
        <?php if ($error): ?>
        <div class="rounded-xl p-3 mb-4 border border-red-800 bg-red-950 text-red-300 text-sm">
            <i class="fa-solid fa-circle-xmark mr-1.5"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Developer password" autofocus
                   class="w-full bg-zinc-900 text-white rounded-xl px-4 py-3 text-sm border border-zinc-700 focus:border-zinc-400 outline-none mb-3">
            <button type="submit" class="w-full py-3 rounded-xl font-bold text-black text-sm" style="background:#76B900;">
                Unlock
            </button>
        </form>
    </div>

<?php else: ?>
    <!-- Generator form -->
    <?php if ($error): ?>
    <div class="rounded-xl p-3 mb-4 border border-red-800 bg-red-950 text-red-300 text-sm">
        <i class="fa-solid fa-triangle-exclamation mr-1.5"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <div class="rounded-2xl p-6 mb-4" style="background:#18181b;">
        <form method="POST" id="gen-form">
            <div class="mb-4">
                <label class="block text-zinc-400 text-xs font-semibold uppercase tracking-wider mb-1.5">Machine GUID</label>
                <input type="text" name="machine_guid" required
                       value="<?= htmlspecialchars($genMachine) ?>"
                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                       class="w-full bg-zinc-900 text-white font-mono rounded-xl px-4 py-3 text-sm border border-zinc-700 focus:border-zinc-400 outline-none">
                <p class="text-zinc-600 text-xs mt-1">Customer runs: <code class="text-zinc-400">php tools/keygen.php --get-machine-id</code></p>
            </div>

            <div class="mb-5">
                <label class="block text-zinc-400 text-xs font-semibold uppercase tracking-wider mb-1.5">Expiry</label>
                <div class="flex gap-2">
                    <input type="text" name="expiry" id="expiry-input"
                           value="<?= htmlspecialchars($genExpiry ?: 'lifetime') ?>"
                           placeholder="lifetime or YYYY-MM-DD"
                           class="flex-1 bg-zinc-900 text-white rounded-xl px-4 py-3 text-sm border border-zinc-700 focus:border-zinc-400 outline-none">
                    <button type="button" onclick="setExpiry('lifetime')"
                            class="px-3 py-2 rounded-xl text-xs font-bold bg-zinc-800 text-zinc-300 hover:bg-zinc-700 transition">∞</button>
                    <button type="button" onclick="setExpiry(offsetDate(365))"
                            class="px-3 py-2 rounded-xl text-xs font-bold bg-zinc-800 text-zinc-300 hover:bg-zinc-700 transition">1yr</button>
                    <button type="button" onclick="setExpiry(offsetDate(365*2))"
                            class="px-3 py-2 rounded-xl text-xs font-bold bg-zinc-800 text-zinc-300 hover:bg-zinc-700 transition">2yr</button>
                </div>
            </div>

            <button type="submit" name="generate" value="1"
                    class="w-full py-3 rounded-xl font-bold text-black text-sm" style="background:#76B900;">
                <i class="fa-solid fa-wand-magic-sparkles mr-2"></i>Generate License
            </button>
        </form>
    </div>

    <?php if ($license): ?>
    <!-- Result -->
    <div class="rounded-2xl p-6" style="background:#0d1f0d; border:1px solid #1a4a1a;">
        <div class="flex items-center gap-2 mb-3">
            <i class="fa-solid fa-circle-check text-green-400"></i>
            <p class="text-green-300 font-bold">License Generated</p>
        </div>
        <p class="text-green-700 text-xs mb-1">Machine: <span class="text-green-500 font-mono"><?= htmlspecialchars($genMachine) ?></span></p>
        <p class="text-green-700 text-xs mb-3">Expiry: <span class="text-green-500"><?= htmlspecialchars($genExpiry) ?></span></p>

        <textarea readonly onclick="this.select()"
                  class="w-full bg-zinc-900 text-zinc-300 font-mono text-xs rounded-xl p-3 border border-zinc-700 resize-none mb-3"
                  rows="4"><?= htmlspecialchars($license) ?></textarea>

        <!-- Download via form POST to avoid page navigation -->
        <form method="POST">
            <input type="hidden" name="download_content" value="<?= htmlspecialchars($license) ?>">
            <button type="submit"
                    class="w-full py-3 rounded-xl font-bold text-black text-sm" style="background:#76B900;">
                <i class="fa-solid fa-download mr-2"></i>Download license.lic
            </button>
        </form>
    </div>
    <?php endif; ?>

    <div class="text-center mt-4">
        <a href="?logout=1" class="text-zinc-600 text-xs hover:text-zinc-400 transition">
            <i class="fa-solid fa-arrow-right-from-bracket mr-1"></i>Lock
        </a>
    </div>
<?php endif; ?>

</div>
<script>
function setExpiry(val) { document.getElementById('expiry-input').value = val; }
function offsetDate(days) {
    const d = new Date(); d.setDate(d.getDate() + days);
    return d.toISOString().split('T')[0];
}
</script>
</body>
</html>
