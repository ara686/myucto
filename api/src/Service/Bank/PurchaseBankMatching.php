<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\LoggingPdo;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\BankStatementOwnershipResolver;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Service\Accounting\Dimension\DimensionPeriodGuard;
use MyInvoice\Support\Sql\PurchaseSettledExpr;
use PDO;
use Psr\Log\LoggerInterface;

/** Dohledání starší platby po přijetí dokladu; peníze zapisuje výhradně StatementMatcher. */
final class PurchaseBankMatching
{
    public const CANDIDATE_LIMIT = 200;

    public function __construct(
        private readonly Connection $db,
        private readonly StatementMatcher $matcher,
        private readonly PermissionResolver $permissions,
        private readonly PermissionChecker $checker,
        private readonly LoggerInterface $logger,
    ) {}

    public function afterReady(int $supplierId, int $id, ?int $userId): void
    {
        $callback = fn () => $this->retry($supplierId, $id, $userId);
        $pdo = $this->db->pdo();
        if ($pdo instanceof LoggingPdo) $pdo->afterCommit("purchase-bank:{$supplierId}:{$id}", $callback);
        elseif (!$pdo->inTransaction()) $callback();
        // Cizí PDO bez afterCommit nesmí provést úhradu před commitem dokladu.
    }

    /** @return array<string,mixed> */
    public function retry(int $supplierId, int $id, ?int $userId): array
    {
        try {
            $role = $userId !== null ? $this->permissions->forSupplierUser($userId, $supplierId) : null;
            if ($role !== null && (!$this->checker->allows($role, 'bank', AccessLevel::READ)
                || !$this->checker->allows($role, 'bank.match', AccessLevel::WRITE)
                || !$this->checker->allows($role, 'purchase_invoices', AccessLevel::READ)
                || ($role->isClientType() && !$this->checker->allows($role, 'purchase_invoices.transition', AccessLevel::WRITE)))) {
                return ['outcome' => 'forbidden'];
            }
            $summary = (new DimensionPeriodGuard($this->db))->transaction(function () use ($supplierId, $id, $role): array {
                // Stejné pořadí zámků jako import banky: kontext firmy, pohyby, doklad.
                (new \MyInvoice\Service\Property\Payments\Workflow\PaymentMutationContext($this->db))->acquire($supplierId);
                $document = $this->document($supplierId, $id);
                if ($document === null) return ['outcome' => 'ineligible'];
                $ids = $this->candidateIds($supplierId, $document);
                if (count($ids) > self::CANDIDATE_LIMIT) return ['outcome' => 'limited', 'matched_count' => 0];
                $context = new BankTransactionContext($this->db);
                $transactions = [];
                foreach ($ids as $txId) {
                    $tx = $context->mutableTransaction($supplierId, $txId);
                    if ($tx !== null) $transactions[$txId] = $tx;
                }
                $document = $this->document($supplierId, $id, true);
                if ($document === null) return ['outcome' => 'ineligible'];
                $dates = [$document['issue_date'], $document['effective_cost_date'] ?? $document['issue_date']];
                foreach ($transactions as $tx) $dates[] = substr((string) $tx['posted_at'], 0, 10);
                if ((new PurchaseBankMatchingPeriodGuard($this->db))->datesLocked($supplierId, $dates)) return ['outcome' => 'locked'];
                if ($role?->isClientType() && ($document['booked_at'] !== null || $document['status'] === 'booked')) {
                    return ['outcome' => 'locked'];
                }
                // Po získání zámků znovu použij celý scope; změna od prvního SELECTu
                // nesmí obejít ignorování, jiné vypořádání ani nahrazené avízo.
                $freeIds = $this->candidateIds($supplierId, $document);
                if (count($freeIds) > self::CANDIDATE_LIMIT) return ['outcome' => 'limited', 'matched_count' => 0];
                $matched = 0;
                foreach ($transactions as $txId => $tx) {
                    if (!in_array($txId, $freeIds, true)) continue;
                    // Dvě vhodné platby k jedinému dokladu vyžadují rozhodnutí člověka.
                    $result = $this->matcher->matchForPurchase($supplierId, $id, $tx, count($freeIds) === 1);
                    if (($result['status'] ?? 'unmatched') !== 'unmatched') ++$matched;
                }
                if ($matched > 0) {
                    // Souhrn zdrojového i měsíčního výpisu musí vidět novou vazbu okamžitě.
                    $statementId = (int) reset($transactions)['statement_id'];
                    $this->db->pdo()->prepare('UPDATE bank_statements SET matched_count = (SELECT COUNT(*) FROM bank_transactions bt WHERE '
                        . StatementTransactionScope::sql($statementId) . " AND bt.match_status IN ('auto_exact','auto_partial','manual')) WHERE id = ?")
                        ->execute([$statementId]);
                    $monthly = new BankApiMonthlyStatements($this->db->pdo());
                    $monthly->refreshMonths($monthly->monthIds($statementId, $supplierId), $supplierId);
                }
                return ['outcome' => 'searched', 'matched_count' => $matched];
            });
        } catch (\Throwable $e) {
            if (TransactionFailure::aborted($e) && $this->db->pdo()->inTransaction()) throw $e;
            $this->logger->error('Dohledání bankovní úhrady selhalo.', ['supplier_id' => $supplierId, 'purchase_invoice_id' => $id, 'exception' => $e]);
            $summary = ['outcome' => 'failed'];
        }
        $summary['checked_at'] = date(DATE_ATOM);
        try {
            $this->db->pdo()->prepare('UPDATE purchase_invoices SET bank_matching_result = ? WHERE supplier_id = ? AND id = ?')
                ->execute([json_encode($summary, JSON_THROW_ON_ERROR), $supplierId, $id]);
        } catch (\Throwable $e) {
            if (TransactionFailure::aborted($e)) throw $e;
            $this->logger->error('Uložení výsledku dohledání banky selhalo.', ['exception' => $e]);
        }
        return $summary;
    }

    /** @return array<string,mixed>|null */
    private function document(int $supplierId, int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->pdo()->prepare("SELECT p.*, c.code AS currency,
                p.amount_to_pay + COALESCE(p.rounding, 0) - (" . PurchaseSettledExpr::settled('p') . ") AS remaining
            FROM purchase_invoices p JOIN currencies c ON c.id = p.currency_id
            WHERE p.supplier_id = ? AND p.id = ? AND p.status IN ('received','booked','paid')
              AND p.document_kind IN ('invoice','receipt','advance') AND p.cash_register_id IS NULL
            HAVING remaining > 0.005" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$supplierId,$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<int> */
    private function candidateIds(int $supplierId, array $document): array
    {
        [$sql, $params] = $this->candidateQuery($supplierId, $document);
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array{string,list<mixed>} */
    private function candidateQuery(int $supplierId, array $document): array
    {
        $symbols = [];
        foreach (['payment_variable_symbol','varsymbol','vendor_invoice_number'] as $field) {
            $raw = (string) ($document[$field] ?? '');
            if ($raw !== '') $symbols[] = $raw;
            $digits = VariableSymbolNormalizer::forMatching($raw);
            if ($digits === '') continue;
            // Bankovní symbol má nejvýše 10 číslic, včetně platných vodicích nul.
            for ($length = strlen($digits); $length <= 10; ++$length) $symbols[] = str_pad($digits, $length, '0', STR_PAD_LEFT);
        }
        $symbols = array_values(array_unique($symbols));
        $parts = [];
        $params = [];
        $scope = ' FROM bank_statements bs JOIN bank_transactions bt ON bt.statement_id = bs.id WHERE '
            . BankStatementOwnershipResolver::sql() . ' AND bt.amount < 0 AND ' . BankPaymentCandidateScope::sql($supplierId);
        if ($symbols !== []) {
            $parts[] = '(SELECT bt.id' . $scope . ' AND bt.variable_symbol IN (' . implode(',',array_fill(0,count($symbols),'?')) . ') LIMIT 201)';
            array_push($params, ...BankStatementOwnershipResolver::params($supplierId), ...$symbols);
        }
        $amount = (float) $document['remaining'];
        $local = FxPaymentSettlement::expectedLocalAmount($amount, (float) ($document['exchange_rate'] ?: 1));
        $tolerance = FxPaymentSettlement::matchTolerance($local, FxPaymentSettlement::AMOUNT_TOLERANCE, true);
        foreach (array_unique([$document['issue_date'],$document['due_date']]) as $date) {
            $day = new \DateTimeImmutable($date);
            $parts[] = '(SELECT bt.id' . $scope . ' AND bt.posted_at >= ? AND bt.posted_at < ?
                AND ((bt.amount BETWEEN ? AND ? AND (bt.currency = ? OR (bt.currency IS NULL AND bs.currency = ?)))
                  OR (bt.amount BETWEEN ? AND ? AND (bt.currency = \'CZK\' OR (bt.currency IS NULL AND bs.currency = \'CZK\')))
                  OR (bt.original_currency = ? AND (bt.original_amount BETWEEN ? AND ? OR bt.original_amount BETWEEN ? AND ?))) LIMIT 201)';
            array_push($params, ...BankStatementOwnershipResolver::params($supplierId));
            array_push($params, $day->modify('-90 days')->format('Y-m-d'),$day->modify('+91 days')->format('Y-m-d'),
                -$amount-1,-$amount+1,$document['currency'],$document['currency'],
                -$local-$tolerance,-$local+$tolerance,$document['currency'],$amount-1,$amount+1,-$amount-1,-$amount+1);
        }
        return [implode(' UNION ', $parts) . ' LIMIT 201', $params];
    }

    /** Čtení nikdy nevytváří úhradu ani návrh. @return array<string,mixed> */
    public function read(int $supplierId, int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT bank_matching_result FROM purchase_invoices WHERE supplier_id = ? AND id = ?');
        $stmt->execute([$supplierId,$id]);
        $json = $stmt->fetchColumn();
        $last = is_string($json) ? json_decode($json,true,512,JSON_THROW_ON_ERROR) : null;
        $stmt = $this->db->pdo()->prepare('SELECT bt.id, bt.statement_id, bt.posted_at, bt.amount, COALESCE(bt.currency, bs.currency, \'CZK\') AS currency, bt.description
            FROM payment_matches pm JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
            JOIN bank_statements bs ON bs.id = bt.statement_id
            WHERE pm.supplier_id = ? AND pm.purchase_invoice_id = ? AND ' . BankStatementOwnershipResolver::sql());
        $stmt->execute([$supplierId,$id,...BankStatementOwnershipResolver::params($supplierId)]);
        $matches = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $document = $this->document($supplierId,$id);
        $ids = $document !== null ? $this->candidateIds($supplierId,$document) : [];
        $suggestions = [];
        if ($ids !== []) {
            $stmt = $this->db->pdo()->prepare('SELECT s.id, s.bank_transaction_id, s.reason, s.candidates_json,
                    bt.statement_id, bt.posted_at, bt.amount, COALESCE(bt.currency, bs.currency, \'CZK\') AS currency, bt.description
                FROM bank_match_suggestions s JOIN bank_transactions bt ON bt.id = s.bank_transaction_id
                JOIN bank_statements bs ON bs.id = bt.statement_id
                WHERE s.supplier_id = ? AND s.status = \'pending\' AND s.bank_transaction_id IN (' . implode(',',array_fill(0,count($ids),'?')) . ')');
            $stmt->execute([$supplierId,...$ids]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                foreach (json_decode($row['candidates_json'],true,512,JSON_THROW_ON_ERROR) as $index => $candidate) {
                    if ((int) ($candidate['purchase_invoice_id'] ?? 0) !== $id) continue;
                    unset($row['candidates_json']);
                    $suggestions[] = $row + ['candidate_index' => $index, 'candidate' => $candidate];
                    break;
                }
            }
        }
        $cast = static function (array $row): array {
            foreach (['id', 'statement_id', 'bank_transaction_id', 'candidate_index'] as $key) {
                if (isset($row[$key])) $row[$key] = (int) $row[$key];
            }
            $row['amount'] = (float) $row['amount'];
            return $row;
        };
        return ['matches' => array_map($cast, $matches), 'suggestions' => array_map($cast, $suggestions), 'last_attempt' => $last];
    }
}
