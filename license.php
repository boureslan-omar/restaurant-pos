<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/license.php';

$status    = License::getStatus();
$machineId = License::getMachineGuid();
$uploadErr = '';
$uploaded  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['lic_file'])) {
    $tmp = $_FILES['lic_file']['tmp_name'] ?? '';
    if ($tmp && is_uploaded_file($tmp)) {
        $content = trim((string)file_get_contents($tmp));
        $decoded = base64_decode($content, true);
        if ($decoded === false || substr_count($decoded, '|') < 2) {
            $uploadErr = 'Invalid license file — make sure you selected the correct .lic file.';
        } else {
            file_put_contents(__DIR__ . '/license.lic', $content);
            header('Location: /restaurant-pos/license.php?installed=1');
            exit;
        }
    } else {
        $uploadErr = 'No file selected or upload failed.';
    }
}

if (!empty($_GET['installed'])) {
    $status   = License::getStatus();
    $uploaded = true;
}

$version = file_exists(__DIR__ . '/version.txt') ? trim((string)file_get_contents(__DIR__ . '/version.txt')) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>License — <?= htmlspecialchars(RESTAURANT_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body class="min-h-screen bg-zinc-950 flex items-center justify-center p-6">
<div class="w-full max-w-md">

    <!-- Header -->
    <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl mb-4" style="background:#76B900;">
            <i class="fa-solid fa-shield-halved text-white text-2xl"></i>
        </div>
        <h1 class="text-white text-2xl font-black"><?= htmlspecialchars(RESTAURANT_NAME) ?></h1>
        <p class="text-zinc-500 text-sm mt-1">License Activation &bull; v<?= htmlspecialchars($version) ?></p>
    </div>

    <!-- License status -->
    <?php if ($status['valid']): ?>
    <div class="rounded-2xl p-5 mb-5 border border-green-800" style="background:#0d1f0d;">
        <div class="flex items-center gap-3 mb-3">
            <i class="fa-solid fa-circle-check text-green-400 text-xl flex-shrink-0"></i>
            <div>
                <p class="text-green-300 font-bold">License Active</p>
                <p class="text-green-700 text-xs mt-0.5">
                    Expires: <?= $status['expiry'] === 'lifetime' ? 'Never (Lifetime)' : htmlspecialchars($status['expiry']) ?>
                </p>
            </div>
        </div>
        <a href="/restaurant-pos/login.php"
           class="block w-full text-center py-2.5 rounded-xl text-sm font-bold text-black transition"
           style="background:#76B900;">
            Continue to Login →
        </a>
    </div>
    <?php else: ?>
    <div class="rounded-2xl p-5 mb-5 border border-red-900" style="background:#1f0d0d;">
        <div class="flex items-center gap-3">
            <i class="fa-solid fa-circle-xmark text-red-500 text-xl flex-shrink-0"></i>
            <div>
                <p class="text-red-300 font-bold">License Required</p>
                <p class="text-red-700 text-xs mt-0.5"><?= htmlspecialchars($status['reason'] ?? 'Unknown error') ?></p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($uploaded && $status['valid']): ?>
    <div class="rounded-xl p-3 mb-4 border border-green-700 bg-green-950 text-green-300 text-sm">
        <i class="fa-solid fa-check mr-1.5"></i>License installed successfully!
    </div>
    <?php elseif ($uploaded && !$status['valid']): ?>
    <div class="rounded-xl p-3 mb-4 border border-red-700 bg-red-950 text-red-300 text-sm">
        <i class="fa-solid fa-triangle-exclamation mr-1.5"></i>File uploaded but validation failed: <?= htmlspecialchars($status['reason'] ?? '') ?>
    </div>
    <?php endif; ?>

    <?php if ($uploadErr): ?>
    <div class="rounded-xl p-3 mb-4 border border-yellow-700 bg-yellow-950 text-yellow-300 text-sm">
        <i class="fa-solid fa-triangle-exclamation mr-1.5"></i><?= htmlspecialchars($uploadErr) ?>
    </div>
    <?php endif; ?>

    <!-- Machine ID -->
    <div class="rounded-2xl p-5 mb-4" style="background:#18181b;">
        <p class="text-zinc-500 text-xs font-semibold uppercase tracking-wider mb-2">Machine ID</p>
        <p class="font-mono text-sm text-white break-all select-all bg-zinc-900 rounded-lg px-3 py-2.5" id="mid"><?= htmlspecialchars($machineId) ?></p>
        <button onclick="copyMachineId(this)" class="mt-2 text-xs text-zinc-600 hover:text-zinc-300 transition">
            <i class="fa-regular fa-copy mr-1"></i>Copy Machine ID
        </button>
        <p class="text-zinc-700 text-xs mt-3 leading-relaxed">
            Send this ID to your software vendor to receive a <code class="text-zinc-500">license.lic</code> file for this machine.
        </p>
    </div>

    <!-- Upload license -->
    <div class="rounded-2xl p-5" style="background:#18181b;">
        <p class="text-zinc-500 text-xs font-semibold uppercase tracking-wider mb-3">Install License File</p>
        <form method="POST" enctype="multipart/form-data">
            <label for="lic_file"
                   class="flex flex-col items-center gap-2 w-full border-2 border-dashed border-zinc-700 rounded-xl p-6 cursor-pointer hover:border-zinc-500 transition text-center">
                <i class="fa-solid fa-file-shield text-zinc-600 text-2xl"></i>
                <span class="text-zinc-500 text-sm" id="file-label">Click to select <code>license.lic</code></span>
                <input type="file" name="lic_file" id="lic_file" accept=".lic" class="hidden"
                       onchange="document.getElementById('file-label').innerHTML = this.files[0]?.name || 'Click to select <code>license.lic</code>'">
            </label>
            <button type="submit" class="mt-3 w-full py-2.5 rounded-xl text-sm font-bold text-black" style="background:#76B900;">
                Install License
            </button>
        </form>
    </div>

</div>

<script>
function copyMachineId(btn) {
    navigator.clipboard.writeText(document.getElementById('mid').textContent).then(() => {
        btn.innerHTML = '<i class="fa-solid fa-check mr-1"></i>Copied!';
        setTimeout(() => { btn.innerHTML = '<i class="fa-regular fa-copy mr-1"></i>Copy Machine ID'; }, 2000);
    });
}
</script>
</body>
</html>
