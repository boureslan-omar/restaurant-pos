<?php
/**
 * Run ONCE from CLI to generate the RSA key pair:
 *   php tools/generate_keys.php
 *
 * Creates:
 *   tools/private.pem  — keep SECRET, never ship to customers
 *   tools/public.pem   — embedded into includes/license.php automatically
 */
if (PHP_SAPI !== 'cli') { die("Run from CLI only.\n"); }

// Ensure OpenSSL can find its config on XAMPP Windows
if (PHP_OS_FAMILY === 'Windows' && empty(getenv('OPENSSL_CONF'))) {
    $candidates = [
        dirname(PHP_BINARY) . '/../apache/conf/openssl.cnf',
        'C:/xampp/apache/conf/openssl.cnf',
    ];
    foreach ($candidates as $c) {
        if (file_exists($c)) { putenv('OPENSSL_CONF=' . realpath($c)); break; }
    }
}

$privFile = __DIR__ . '/private.pem';
$pubFile  = __DIR__ . '/public.pem';
$licFile  = __DIR__ . '/../includes/license.php';

if (file_exists($privFile)) {
    echo "WARNING: $privFile already exists.\n";
    echo "Re-running will invalidate ALL existing licenses.\n";
    echo "Type YES to continue, or anything else to abort: ";
    $ans = trim(fgets(STDIN));
    if ($ans !== 'YES') { echo "Aborted.\n"; exit(0); }
}

echo "Generating 2048-bit RSA key pair...\n";
$res = openssl_pkey_new([
    'digest_alg'       => 'sha256',
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
if (!$res) {
    die('openssl_pkey_new() failed: ' . openssl_error_string() . "\n");
}

openssl_pkey_export($res, $privateKey);
file_put_contents($privFile, $privateKey);
echo "Private key → $privFile\n";
echo "  !! KEEP THIS SECRET — never ship it to customers !!\n\n";

$details   = openssl_pkey_get_details($res);
$publicKey = $details['key'];
file_put_contents($pubFile, $publicKey);
echo "Public key  → $pubFile\n";

// Patch includes/license.php — replace the nowdoc block between EOK markers
if (!file_exists($licFile)) {
    echo "\nWARNING: $licFile not found.\n";
    echo "Paste the public key manually into License::PUBLIC_KEY.\n";
    echo "\n--- PUBLIC KEY ---\n$publicKey--- END ---\n";
    exit(0);
}

$content = file_get_contents($licFile);
$pubTrimmed = trim($publicKey);
$updated = preg_replace(
    "/private const PUBLIC_KEY = <<<'EOK'\r?\n.*?\r?\nEOK;/s",
    "private const PUBLIC_KEY = <<<'EOK'\n{$pubTrimmed}\nEOK;",
    $content
);

if ($updated === null || $updated === $content) {
    echo "\nWARNING: Could not auto-patch $licFile (pattern not found).\n";
    echo "Paste the following between the EOK markers in License::PUBLIC_KEY:\n\n";
    echo $publicKey;
} else {
    file_put_contents($licFile, $updated);
    echo "\nPublic key embedded into includes/license.php ✓\n";
}

echo "\nDone! Next step: use tools/keygen.php to issue license files.\n";
