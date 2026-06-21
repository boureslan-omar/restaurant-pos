<?php
class Updater
{
    private const CACHE_FILE = __DIR__ . '/../.update_check';
    private const CACHE_TTL  = 3600; // re-check at most once per hour

    public static function checkAndApply(bool $verbose = false): void
    {
        if (!defined('GITHUB_OWNER') || !defined('GITHUB_REPO')
            || GITHUB_OWNER === 'YOUR_GITHUB_USERNAME' || GITHUB_OWNER === '') {
            if ($verbose) echo "GitHub repo not configured in config.php — skipping update check.\n";
            return;
        }

        $current = self::currentVersion();
        if ($verbose) echo "Current version: v$current\n";

        if (!$verbose && self::checkedRecently()) return;

        if ($verbose) echo 'Checking ' . GITHUB_OWNER . '/' . GITHUB_REPO . " for updates...\n";

        $remote = self::fetchRemoteVersion();
        file_put_contents(self::CACHE_FILE, time());

        if ($remote === null) {
            if ($verbose) echo "Could not reach update server (no internet?) — skipping.\n";
            return;
        }

        if ($verbose) echo "Latest version:  v$remote\n";

        if (version_compare($remote, $current, '<=')) {
            if ($verbose) echo "Already up to date.\n";
            return;
        }

        if ($verbose) echo "\nUpdate available: v$current → v$remote\n";
        self::applyUpdate($remote, $verbose);
    }

    public static function currentVersion(): string
    {
        $f = __DIR__ . '/../version.txt';
        return file_exists($f) ? trim((string)file_get_contents($f)) : '0.0.0';
    }

    private static function checkedRecently(): bool
    {
        if (!file_exists(self::CACHE_FILE)) return false;
        return (time() - (int)file_get_contents(self::CACHE_FILE)) < self::CACHE_TTL;
    }

    private static function rawUrl(string $file): string
    {
        return 'https://raw.githubusercontent.com/' . GITHUB_OWNER . '/' . GITHUB_REPO . '/main/' . $file;
    }

    private static function fetchRemoteVersion(): ?string
    {
        $ctx  = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "User-Agent: RestaurantPOS-Updater/1.0\r\n",
            'timeout'       => 10,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents(self::rawUrl('version.txt'), false, $ctx);
        if (!$body) return null;
        $v = trim($body);
        return preg_match('/^\d+\.\d+\.\d+$/', $v) ? $v : null;
    }

    private static function applyUpdate(string $newVersion, bool $verbose): void
    {
        if ($verbose) echo "Downloading upgrade.php...\n";

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "User-Agent: RestaurantPOS-Updater/1.0\r\n",
            'timeout'       => 30,
            'ignore_errors' => true,
        ]]);
        $script = @file_get_contents(self::rawUrl('upgrade.php'), false, $ctx);

        if (!$script || str_starts_with(trim($script), '404')) {
            if ($verbose) echo "upgrade.php not found on GitHub — skipping.\n";
            return;
        }

        $localUpgrade = __DIR__ . '/../upgrade.php';
        file_put_contents($localUpgrade, $script);

        if ($verbose) echo "Running upgrade.php...\n";

        $output = [];
        $code   = 0;
        exec('"' . PHP_BINARY . '" "' . $localUpgrade . '" 2>&1', $output, $code);

        foreach ($output as $line) {
            if ($verbose) echo "  $line\n";
        }

        if ($code === 0) {
            if ($verbose) echo "\nUpdate complete — now running v$newVersion.\n\n";
        } else {
            if ($verbose) echo "\nupgrade.php exited with code $code — check the script.\n";
        }
    }
}
