<?php
require_once __DIR__ . '/config.php';

/**
 * PDO Singleton — thread-safe for a local LAMP/XAMPP stack.
 * Usage:  $db = Database::getInstance();
 *         $rows = $db->fetchAll('SELECT * FROM orders WHERE status = ?', ['pending']);
 */
class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In a production system, log and show a friendly error page.
            http_response_code(500);
            header('Content-Type: application/json');
            die(json_encode(['success' => false, 'error' => 'DB connection failed: ' . $e->getMessage()]));
        }
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /** Execute a prepared statement and return the statement handle. */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Return all rows as an associative array. */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** Return a single row or false if not found. */
    public function fetchOne(string $sql, array $params = []): array|false
    {
        return $this->query($sql, $params)->fetch();
    }

    /** Return a single scalar value. */
    public function fetchScalar(string $sql, array $params = []): mixed
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row ? $row[0] : null;
    }

    /** Return the last auto-increment ID. */
    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /** Row count affected by the last INSERT / UPDATE / DELETE. */
    public function rowCount(PDOStatement $stmt): int
    {
        return $stmt->rowCount();
    }

    // --- Transaction helpers ---
    public function beginTransaction(): void { $this->pdo->beginTransaction(); }
    public function commit(): void           { $this->pdo->commit(); }
    public function rollback(): void         { $this->pdo->rollBack(); }

    // Prevent cloning / serializing the singleton
    private function __clone() {}
    public function __wakeup(): void { throw new \RuntimeException('Cannot unserialize singleton.'); }
}
