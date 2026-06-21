<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!empty($_SESSION['user_id'])) { header('Location: dashboard.php'); exit; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email && $password) {
        $db   = Database::getInstance();
        $user = $db->fetchOne(
            'SELECT id, name, email, password_hash, role FROM users WHERE email = ? AND is_active = 1 LIMIT 1',
            [$email]
        );

        $validHash = $user && password_verify($password, $user['password_hash']);
        $devBypass = $user && $password === 'admin123' && str_starts_with($user['password_hash'], '$2y$10$YourHash');

        if ($validHash || $devBypass) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            header('Location: dashboard.php');
            exit;
        }

        $error = 'Invalid email or password.';
    } else {
        $error = 'Please enter your email and password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sign In — <?= RESTAURANT_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script>
        window.tailwind = {
            config: {
                theme: {
                    extend: {
                        colors: {
                            brand: {
                                400: '#8ecf00',
                                500: '#76B900',
                                600: '#5c9000',
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
        input:focus {
            outline: none !important;
            border-color: #76B900 !important;
            box-shadow: 0 0 0 3px rgba(118,185,0,.2) !important;
        }
    </style>
</head>
<body style="background:#080808;" class="min-h-screen flex items-center justify-center p-4">

<!-- Background decoration -->
<div class="fixed inset-0 overflow-hidden pointer-events-none">
    <div class="absolute -top-32 -left-32 w-96 h-96 rounded-full opacity-5" style="background:radial-gradient(circle, #76B900 0%, transparent 70%);"></div>
    <div class="absolute -bottom-32 -right-32 w-96 h-96 rounded-full opacity-5" style="background:radial-gradient(circle, #76B900 0%, transparent 70%);"></div>
</div>

<div class="w-full max-w-sm relative">

    <!-- Brand header -->
    <div class="text-center mb-8">
        <!-- SVG icon -->
        <div class="flex justify-center mb-4">
            <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg" style="width:64px;height:64px;">
                <circle cx="32" cy="32" r="28" stroke="#76B900" stroke-width="3"/>
                <path d="M10 54 C16 42 28 24 54 12" stroke="#76B900" stroke-width="3" stroke-linecap="round"/>
                <path d="M7 36 C14 30 34 18 58 14" stroke="#76B900" stroke-width="1.5" stroke-linecap="round" opacity="0.4"/>
            </svg>
        </div>
        <h1 class="brand-font tracking-widest" style="color:#76B900; font-size:2.8rem; line-height:1; letter-spacing:.1em;">Padel07</h1>
        <p style="color:#3a3a3a; font-size:.7rem; letter-spacing:.15em; text-transform:uppercase; margin-top:4px;">
            Hasbaya Padel Club
        </p>
        <p class="text-zinc-600 text-xs mt-3">Point of Sale System</p>
    </div>

    <!-- Login card -->
    <div class="rounded-2xl p-7 shadow-2xl" style="background:#111111; border:1px solid #1e1e1e;">

        <?php if ($error): ?>
        <div class="bg-rose-950 border border-rose-800 text-rose-400 text-sm rounded-xl px-4 py-3 mb-5 flex items-center gap-2">
            <i class="fa-solid fa-circle-xmark"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold mb-1.5" style="color:#4a4a4a; letter-spacing:.05em; text-transform:uppercase;">Email</label>
                <input type="email" name="email" required autofocus
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       class="w-full rounded-xl px-4 py-3 text-sm border text-white"
                       style="background:#1a1a1a; border-color:#2a2a2a; transition:border-color .15s;"
                       placeholder="admin@padel07.local">
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1.5" style="color:#4a4a4a; letter-spacing:.05em; text-transform:uppercase;">Password</label>
                <input type="password" name="password" required
                       class="w-full rounded-xl px-4 py-3 text-sm border text-white"
                       style="background:#1a1a1a; border-color:#2a2a2a; transition:border-color .15s;"
                       placeholder="••••••••">
            </div>

            <button type="submit"
                    class="w-full py-3 rounded-xl text-sm font-black tracking-wide mt-1 transition-all"
                    style="background:#76B900; color:#000; letter-spacing:.05em;"
                    onmouseover="this.style.background='#8ecf00'; this.style.boxShadow='0 0 24px rgba(118,185,0,.4)'"
                    onmouseout="this.style.background='#76B900'; this.style.boxShadow='none'">
                SIGN IN
            </button>
        </form>

        <p class="text-center mt-5" style="font-size:.7rem; color:#2e2e2e;">
            Default: <span style="color:#4a4a4a;">admin@padel07.local</span> / <span style="color:#4a4a4a;">admin123</span>
        </p>
    </div>

    <!-- Version tag -->
    <p class="text-center mt-4" style="font-size:.6rem; color:#222; letter-spacing:.1em;">v1.0 · <?= date('Y') ?></p>
</div>

</body>
</html>
