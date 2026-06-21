<?php
class License
{
    // RSA-2048 public key — embedded by tools/generate_keys.php
    // Run that script once: php tools/generate_keys.php
    private const PUBLIC_KEY = <<<'EOK'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAnlSQCoiVDvywXtfpO0/Y
nG3bi1sq1w6aRXOC5y6o1zYjfqjWY1uTdOwlnWnqMzvbUC0odOTZNkPHTdo2Of++
9L+aRSP2lTDLtkE99XwjJ4QNfZ3yeRtn28+aga+05MTt2+Ge6Ly6lci9w1pP2SVA
+aNlFvWqvMmI+/0iHfrbhZ4vXh824mAsQdVeE8L/L5oNDsuF/+9aLtikEPprLXId
1a5Xxy40P5ks7V3pqOYPfB1b5vQJXzUIwegtStK+qWD94PSLr0fkl9ppBxPOsToE
LpdVBzpZNG0cUxxD6LPubQuAPb4q0OH5DHLeIamAjY1z5IwUcRCSYVLEMEnfFbMM
UwIDAQAB
-----END PUBLIC KEY-----
EOK;

    private const LIC_FILE = __DIR__ . '/../license.lic';

    /** Windows Machine GUID from registry, or hostname hash as fallback. */
    public static function getMachineGuid(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            @exec('reg query HKLM\\SOFTWARE\\Microsoft\\Cryptography /v MachineGuid 2>&1', $out);
            foreach ($out as $line) {
                if (stripos($line, 'MachineGuid') !== false) {
                    $parts = preg_split('/\s+/', trim($line));
                    $guid  = end($parts);
                    if (preg_match('/^[0-9a-f\-]{36}$/i', $guid)) {
                        return strtolower($guid);
                    }
                }
            }
        }
        return md5(gethostname() ?: 'unknown');
    }

    public static function isValid(): bool
    {
        return self::getStatus()['valid'];
    }

    public static function getStatus(): array
    {
        if (self::PUBLIC_KEY === 'REPLACE_WITH_PUBLIC_KEY') {
            return ['valid' => false, 'reason' => 'License system not initialised — run php tools/generate_keys.php'];
        }

        if (!file_exists(self::LIC_FILE)) {
            return ['valid' => false, 'reason' => 'No license file found (license.lic missing)', 'machine_id' => self::getMachineGuid()];
        }

        $raw = trim((string)file_get_contents(self::LIC_FILE));
        if ($raw === '') {
            return ['valid' => false, 'reason' => 'License file is empty', 'machine_id' => self::getMachineGuid()];
        }

        $decoded = base64_decode($raw, true);
        if ($decoded === false || substr_count($decoded, '|') < 2) {
            return ['valid' => false, 'reason' => 'License file is corrupt or invalid format', 'machine_id' => self::getMachineGuid()];
        }

        [$machineGuid, $expiry, $signature] = explode('|', $decoded, 3);

        // Machine check
        $currentGuid = self::getMachineGuid();
        if (strtolower($machineGuid) !== strtolower($currentGuid)) {
            return ['valid' => false, 'reason' => 'License is not valid for this machine', 'machine_id' => $currentGuid];
        }

        // Expiry check
        if ($expiry !== 'lifetime') {
            $ts = strtotime($expiry);
            if ($ts === false || $ts < time()) {
                return ['valid' => false, 'reason' => 'License expired on ' . $expiry, 'expiry' => $expiry, 'machine_id' => $machineGuid];
            }
        }

        // RSA signature check
        $pubKey = openssl_pkey_get_public(self::PUBLIC_KEY);
        if ($pubKey === false) {
            return ['valid' => false, 'reason' => 'Public key error — reinstall or contact developer', 'machine_id' => $machineGuid];
        }
        $ok = openssl_verify($machineGuid . '|' . $expiry, $signature, $pubKey, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            return ['valid' => false, 'reason' => 'License signature is invalid or tampered', 'machine_id' => $machineGuid];
        }

        return [
            'valid'      => true,
            'machine_id' => $machineGuid,
            'expiry'     => $expiry,
            'reason'     => null,
        ];
    }
}
