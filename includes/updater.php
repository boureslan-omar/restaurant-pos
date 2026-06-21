<?php
class Updater
{
    private const CACHE_FILE = __DIR__ . '/../.update_check';
    private const PROTECTED  = ['config.php', 'license.lic', 'schema.sql', '.update_check', '.htaccess'];
    private const TIMEOUT_S  = 3600; // re-check at most once per hour

    public static function checkAndApply(bool $verbose = false): void
    {
        $current = self::currentVersion();
        if ($verbose) echo "Current version: v$current\n";

        if (!defined('GITHUB_OWNER') || !defined('GITHUB_REPO')
            || GITHUB_OWNER === 'YOUR_GITHUB_USERNAME' || GITHUB_OWNER === '') {
            if ($verbose) echo "GitHub repo not configured in config.php — skipping.\n";
            return;
        }

        if (!$verbose && self::checkedRecently()) {
            return;
        }

        if ($verbose) echo 'Checking ' . GITHUB_OWNER . '/' . GITHUB_REPO . " for updates...\n";

        $release = self::fetchLatest();
        file_put_contents(self::CACHE_FILE, time());

        if (!$release) {
            if ($verbose) echo "Could not reach GitHub API (no internet?) — skipping.\n";
            return;
        }

        $latest = ltrim($release['tag_name'] ?? '', 'v');
        if ($verbose) echo "Latest release: v$latest\n";

        if (version_compare($latest, $current, '<=')) {
            if ($verbose) echo "Already up to date.\n";
            return;
        }

        if ($verbose) echo "Updating v$current → v$latest...\n";

        $zipUrl = $release['zipball_url'] ?? '';
        if (!$zipUrl) {
            if ($verbose) echo "No download URL in release — skipping.\n";
            return;
        }

        self::applyUpdate($zipUrl, $latest, $verbose);
    }

    public static function currentVersion(): string
    {
        $f = __DIR__ . '/../version.txt';
        return file_exists($f) ? trim((string)file_get_contents($f)) : '0.0.0';
    }

    private static function checkedRecently(): bool
    {
        if (!file_exists(self::CACHE_FILE)) return false;
        return (time() - (int)file_get_contents(self::CACHE_FILE)) < self::TIMEOUT_S;
    }

    private static function fetchLatest(): ?array
    {
        $url = 'https://api.github.com/repos/' . GITHUB_OWNER . '/' . GITHUB_REPO . '/releases/latest';
        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'header'          => "User-Agent: RestaurantPOS-Updater/1.0\r\nAccept: application/vnd.github.v3+json\r\n",
            'timeout'         => 10,
            'ignore_errors'   => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if (!$body) return null;
        $data = json_decode($body, true);
        return (is_array($data) && isset($data['tag_name'])) ? $data : null;
    }

    private static function applyUpdate(string $zipUrl, string $newVersion, bool $verbose): void
    {
        if (!class_exists('ZipArchive')) {
            if ($verbose) echo "PHP ZipArchive not available — cannot auto-update.\n";
            return;
        }

        // Download
        $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_update_' . time() . '.zip';
        $ctx = stream_context_create(['http' => [
            'method'           => 'GET',
            'header'           => "User-Agent: RestaurantPOS-Updater/1.0\r\n",
            'timeout'          => 60,
            'follow_location'  => 1,
            'max_redirects'    => 5,
        ]]);
        if ($verbose) echo "Downloading update package...\n";
        $data = @file_get_contents($zipUrl, false, $ctx);
        if (!$data) {
            if ($verbose) echo "Download failed.\n";
            return;
        }
        file_put_contents($tmpZip, $data);

        // Extract
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pos_update_' . time();
        $zip    = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            if ($verbose) echo "Could not open zip archive.\n";
            @unlink($tmpZip);
            return;
        }
        $zip->extractTo($tmpDir);
        $zip->close();
        @unlink($tmpZip);

        // GitHub zips have a single root folder (owner-repo-SHA/)
        $dirs      = glob($tmpDir . '/*', GLOB_ONLYDIR);
        $sourceDir = $dirs[0] ?? $tmpDir;
        $appRoot   = realpath(__DIR__ . '/..');

        self::copyDir($sourceDir, $appRoot, $verbose);

        file_put_contents($appRoot . '/version.txt', $newVersion);
        self::rmRecursive($tmpDir);

        if ($verbose) echo "Update complete — now running v$newVersion.\n";
    }

    private static function copyDir(string $src, string $dst, bool $verbose): void
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $item) {
            $rel  = ltrim(substr($item->getPathname(), strlen($src)), DIRECTORY_SEPARATOR . '/');
            $dest = $dst . DIRECTORY_SEPARATOR . $rel;
            $base = basename($rel);
            if (in_array($base, self::PROTECTED)) {
                if ($verbose) echo "  Kept:    $rel\n";
                continue;
            }
            if ($item->isDir()) {
                @mkdir($dest, 0755, true);
            } else {
                copy($item->getPathname(), $dest);
                if ($verbose) echo "  Updated: $rel\n";
            }
        }
    }

    private static function rmRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? self::rmRecursive($f) : @unlink($f);
        }
        @rmdir($dir);
    }
}
