<?php
/**
 * License key generator — run from CLI:
 *
 *   php tools/keygen.php
 *       Interactive mode — prompts for machine GUID and expiry
 *
 *   php tools/keygen.php --machine=GUID --expiry=YYYY-MM-DD
 *   php tools/keygen.php --machine=GUID --expiry=lifetime
 *       Non-interactive; output saved to license.lic in current dir
 *
 *   php tools/keygen.php --get-machine-id
 *       Print this machine's GUID (useful for your own install)
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

require_once __DIR__ . '/../includes/license.php';

$opts = getopt('', ['machine:', 'expiry:', 'out:', 'get-machine-id']);

// --get-machine-id shortcut
if (isset($opts['get-machine-id'])) {
    echo 'Machine ID: ' . License::getMachineGuid() . "\n";
    exit(0);
}

$privFile = __DIR__ . '/private.pem';
if (!file_exists($privFile)) {
    die("ERROR: $privFile not found.\nRun: php tools/generate_keys.php first.\n");
}
$privateKey = openssl_pkey_get_private((string)file_get_contents($privFile));
if (!$privateKey) {
    die('ERROR: Could not load private key: ' . openssl_error_string() . "\n");
}

// --- Machine GUID ---
if (!empty($opts['machine'])) {
    $machineGuid = strtolower(trim($opts['machine']));
} else {
    $myGuid = License::getMachineGuid();
    echo "This machine's GUID: $myGuid\n";
    echo "Enter target machine GUID (leave blank to use this machine): ";
    $input = trim(fgets(STDIN));
    $machineGuid = strtolower($input ?: $myGuid);
}

if (!preg_match('/^[0-9a-f\-]{36}$/i', $machineGuid) && strlen($machineGuid) !== 32) {
    die("ERROR: Invalid GUID format: $machineGuid\n");
}

// --- Expiry ---
if (!empty($opts['expiry'])) {
    $expiry = $opts['expiry'];
} else {
    echo "Enter expiry date (YYYY-MM-DD or 'lifetime') [lifetime]: ";
    $expiry = trim(fgets(STDIN)) ?: 'lifetime';
}

if ($expiry !== 'lifetime' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
    die("ERROR: Invalid expiry format. Use YYYY-MM-DD or 'lifetime'.\n");
}
if ($expiry !== 'lifetime' && strtotime($expiry) < time()) {
    die("ERROR: Expiry date $expiry is in the past.\n");
}

// --- Sign ---
$payload = $machineGuid . '|' . $expiry;
if (!openssl_sign($payload, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
    die('ERROR: Signing failed: ' . openssl_error_string() . "\n");
}

$licenseContent = base64_encode($payload . '|' . $signature);

// --- Output file ---
$outFile = $opts['out'] ?? getcwd() . DIRECTORY_SEPARATOR . 'license.lic';
file_put_contents($outFile, $licenseContent);

echo "\n";
echo "===========================================\n";
echo " LICENSE GENERATED\n";
echo "===========================================\n";
echo " Machine : $machineGuid\n";
echo " Expiry  : $expiry\n";
echo " File    : $outFile\n";
echo "===========================================\n";
echo "\nSend license.lic to the customer.\n";
echo "They place it in the restaurant-pos/ root folder.\n";
