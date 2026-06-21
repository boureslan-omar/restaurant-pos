<?php
/**
 * Settings helper — reads/writes key-value pairs from the `settings` table.
 * Auto-loads on first access (lazy, uses the Database singleton).
 */
class Settings
{
    private static ?array $cache = null;

    private static function ensureLoaded(): void
    {
        if (self::$cache !== null) return;
        try {
            $db   = Database::getInstance();
            $rows = $db->fetchAll('SELECT `key`, value FROM settings');
            self::$cache = array_column($rows, 'value', 'key');
        } catch (\Exception $e) {
            self::$cache = [];   // fallback to empty on DB error
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::ensureLoaded();
        $val = self::$cache[$key] ?? null;
        return $val !== null ? $val : $default;
    }

    public static function set(string $key, string $value): void
    {
        self::ensureLoaded();
        Database::getInstance()->query(
            'INSERT INTO settings (`key`, value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()',
            [$key, $value, $value]
        );
        self::$cache[$key] = $value;
    }

    /** Tax rate as a float (0 – 100). Falls back to TAX_RATE constant. */
    public static function taxRate(): float
    {
        return max(0.0, (float)self::get('tax_rate', TAX_RATE));
    }

    /** LBP exchange rate (1 USD = X LBP). Returns 0 if not configured. */
    public static function lbpRate(): int
    {
        return max(0, (int)self::get('exchange_rate_usd_lbp', 0));
    }
}
