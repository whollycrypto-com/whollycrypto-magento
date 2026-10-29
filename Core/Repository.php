<?php
declare(strict_types=1);

namespace WhollyCrypto\Payment\Core;

/** Small durable journal. All callbacks must use the same native DB connection. */
final class Repository
{
    private $execute;
    private $rows;
    private $transaction;
    private string $table;

    public function __construct(callable $execute, callable $rows, callable $transaction, string $table)
    {
        if (!preg_match('/\A[a-zA-Z0-9_]+\z/D', $table)) { throw new \InvalidArgumentException('Invalid table.'); }
        $this->execute = $execute;
        $this->rows = $rows;
        $this->transaction = $transaction;
        $this->table = $table;
    }

    public function install(array $nativeTables): void
    {
        foreach ($nativeTables as $name) {
            $rows = ($this->rows)('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$name]);
            if (!$rows || strcasecmp($rows[0]['ENGINE'], 'InnoDB') !== 0) {
                throw new \RuntimeException('Wholly Crypto requires InnoDB order and payment tables.');
            }
        }
        ($this->execute)("CREATE TABLE IF NOT EXISTS `{$this->table}` (
            order_id VARCHAR(64) NOT NULL PRIMARY KEY,
            invoice_id CHAR(36) NULL,
            record LONGTEXT NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY invoice_unique (invoice_id), KEY reconcile_queue (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci", []);
    }

    public function load(string $id): ?array
    {
        $rows = ($this->rows)("SELECT record FROM `{$this->table}` WHERE order_id=?", [$id]);
        return $rows ? json_decode($rows[0]['record'], true, 64, JSON_THROW_ON_ERROR) : null;
    }

    public function save(array $record): void
    {
        $body = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($this->load($record['order_id']) !== null) {
            ($this->execute)("UPDATE `{$this->table}` SET invoice_id=?,record=?,updated_at=UTC_TIMESTAMP() WHERE order_id=?", [$record['invoice_id'], $body, $record['order_id']]);
        } else {
            // An invoice-id collision must throw, never update another order's row.
            ($this->execute)("INSERT INTO `{$this->table}` (order_id,invoice_id,record,updated_at) VALUES (?,?,?,UTC_TIMESTAMP())", [$record['order_id'], $record['invoice_id'], $body]);
        }
    }

    public function locked(string $id, callable $work)
    {
        $key = 'wc:' . substr(hash('sha256', $this->table . ':' . $id), 0, 55);
        $rows = ($this->rows)('SELECT GET_LOCK(?,0) AS acquired', [$key]);
        if ((int) ($rows[0]['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('Payment verification is already running. Retry shortly.');
        }
        try { return $work(); } finally { ($this->rows)('SELECT RELEASE_LOCK(?)', [$key]); }
    }

    public function atomic(callable $work)
    {
        return ($this->transaction)($work);
    }

    public function next(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return array_column(($this->rows)("SELECT order_id FROM `{$this->table}` ORDER BY updated_at,order_id LIMIT $limit", []), 'order_id');
    }
}
