<?php

declare(strict_types=1);

namespace MyInvoice\Service\Anonymization\Streaming;

use PDO;

final class ChunkedJsonColumnReader
{
    public const CHUNK_BYTES = 524_288;

    public function __construct(private readonly PDO $pdo) {}

    /** @return iterable<string> */
    public function read(FinancialArchiveJsonColumn $source, int $supplierId, int $id): iterable
    {
        if ($supplierId < 1 || $id < 1) throw new \InvalidArgumentException('Archivní JSON vyžaduje přesný tenant a primární klíč.');
        if (!$this->pdo->inTransaction()) throw new \LogicException('Čtení archivního JSON vyžaduje vlastní transakci.');
        $table = $source->table();
        $column = $source->column();
        $metadataSql = "SELECT OCTET_LENGTH({$column}) AS bytes, SHA2({$column},256) AS sha256 FROM {$table} WHERE id=? AND supplier_id=? FOR UPDATE";
        $metadata = $this->metadata($metadataSql, $id, $supplierId);
        $savepoint = 'json_chunk_' . bin2hex(random_bytes(12));
        $this->pdo->exec('SAVEPOINT ' . $savepoint);
        $activeSavepoint = true;
        try {
            for ($offset = 1; $offset <= $metadata['bytes']; $offset += self::CHUNK_BYTES) {
                $this->advanceSavepoint($savepoint);
                $statement = $this->pdo->prepare("SELECT SUBSTRING(CAST({$column} AS BINARY),?,?) FROM {$table} WHERE id=? AND supplier_id=?");
                $statement->execute([$offset, self::CHUNK_BYTES, $id, $supplierId]);
                $chunk = $statement->fetchColumn();
                if (!is_string($chunk) || $chunk === '' || strlen($chunk) > self::CHUNK_BYTES) {
                    throw new \RuntimeException('Archivní JSON se během čtení změnil.');
                }
                yield $chunk;
            }
            $this->advanceSavepoint($savepoint);
            $current = $this->metadata($metadataSql, $id, $supplierId);
            if ($current !== $metadata) throw new \RuntimeException('Archivní JSON se během čtení změnil.');
            $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            $activeSavepoint = false;
        } finally {
            if ($activeSavepoint && $this->pdo->inTransaction()) {
                try {
                    $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
                } catch (\Throwable) {
                }
            }
        }
    }

    /** @return array{bytes:int,sha256:string} */
    private function metadata(string $sql, int $id, int $supplierId): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$id, $supplierId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !is_numeric($row['bytes'] ?? null) || !is_string($row['sha256'] ?? null)) {
            throw new \RuntimeException('Archivní JSON nebyl nalezen.');
        }
        return ['bytes' => (int) $row['bytes'], 'sha256' => strtolower($row['sha256'])];
    }

    private function advanceSavepoint(string $savepoint): void
    {
        if (!$this->pdo->inTransaction()) throw new \RuntimeException('Transakce archivního JSON byla ukončena.');
        try {
            $this->pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Transakce archivního JSON byla nahrazena.', 0, $exception);
        }
        $this->pdo->exec('SAVEPOINT ' . $savepoint);
    }
}
