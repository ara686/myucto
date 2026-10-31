<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Volající drží zámky období a kontextu firmy; používá automat i potvrzení návrhu. */
final class PurchaseBankMatchingPeriodGuard
{
    public function __construct(private readonly Connection $db) {}

    /** @param list<string> $dates */
    public function datesLocked(int $supplierId, array $dates): bool
    {
        $stmt = $this->db->pdo()->prepare("SELECT starts_on, ends_on FROM accounting_periods
            WHERE supplier_id = ? AND status IN ('closing','closed','approved')");
        $stmt->execute([$supplierId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $period) {
            foreach ($dates as $date) if ($date >= $period['starts_on'] && $date <= $period['ends_on']) return true;
        }
        $stmt = $this->db->pdo()->prepare('SELECT locked_until FROM accounting_supplier_settings WHERE supplier_id = ?');
        $stmt->execute([$supplierId]);
        $until = $stmt->fetchColumn();
        return is_string($until) && $until !== '' && min($dates) <= $until;
    }

}
