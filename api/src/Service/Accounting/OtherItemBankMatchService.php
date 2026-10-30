<?php

declare(strict_types=1);

namespace MyInvoice\Service\Accounting;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Accounting\Bank\BankPostingService;
use PDO;

/**
 * Párování bankovního pohybu s ostatní pohledávkou nebo závazkem z dialogu „Spárovat"
 * u pohybu. Protějšek výběru platby z detailu položky ({@see OtherItemService::paymentCandidates}).
 *
 * Přiřazení samo nic nového nepočítá: nezaúčtovaný pohyb zaúčtuje ručním zápisem
 * banka / účet pohledávky či závazku ({@see BankPostingService::postManual}) a úhradu
 * přiřadí přes {@see OtherItemService::allocate}, který hlídá přeplatek, měnu, směr
 * i zaúčtování proti správnému účtu.
 */
final class OtherItemBankMatchService
{
    private const AMOUNT_TOLERANCE = 0.01;

    public function __construct(
        private readonly Connection $db,
        private readonly OtherItemService $items,
        private readonly BankPostingService $bankPosting,
    ) {}

    /**
     * Otevřené ostatní položky, které jde k pohybu přiřadit. Bez hledání nabídne shodu
     * zbývající částky nebo variabilního symbolu, s hledáním položky podle čísla, VS,
     * partnera či názvu bez ohledu na částku.
     *
     * @return list<array{type:string,id:int,ref:?string,amount:float,currency:string,
     *   converted_amount:null,converted_currency:null,issue_date:string,due_date:?string,
     *   party:?string,paid:bool,currency_mismatch:bool,side:string}>
     */
    public function candidates(int $supplierId, int $txId, string $search = '', int $limit = 25): array
    {
        $tx = $this->freeTransaction($supplierId, $txId, false);
        if ($tx === null) return [];
        $limit = max(1, min(50, $limit));
        $free = round(abs((float) $tx['amount']) - (float) $tx['used_amount'], 2);
        $remaining = 'oi.amount - oi.settled_outside_amount - COALESCE(p.paid_amount, 0)';
        $params = [$supplierId, $supplierId, (float) $tx['amount'] > 0 ? 'receivable' : 'payable'];
        $where = '';
        if ($tx['locked_item_id'] !== null) {
            // Po stornu přiřazení smí platba zpět jen k původní položce (pravidlo allocate()).
            $where .= ' AND oi.id = ?';
            $params[] = (int) $tx['locked_item_id'];
        }
        $search = trim($search);
        if ($search !== '') {
            $term = '%' . addcslashes($search, '%_\\') . '%';
            $where .= ' AND (oi.document_no LIKE ? OR oi.variable_symbol LIKE ? OR oi.partner_name LIKE ? OR oi.title LIKE ?)';
            array_push($params, $term, $term, $term, $term);
        } else {
            $where .= " AND (ABS({$remaining} - ?) <= ? OR (oi.variable_symbol <> '' AND oi.variable_symbol = ?))";
            array_push($params, $free, self::AMOUNT_TOLERANCE, (string) ($tx['variable_symbol'] ?? ''));
        }
        $stmt = $this->db->pdo()->prepare(
            "SELECT oi.id, oi.document_no, oi.title, oi.partner_name, oi.issued_on, oi.due_on, oi.side,
                    {$remaining} AS remaining_amount
               FROM other_items oi
          LEFT JOIN (SELECT other_item_id, SUM(amount) paid_amount FROM other_item_allocations
                      WHERE supplier_id = ? AND reversed_on IS NULL GROUP BY other_item_id) p
                 ON p.other_item_id = oi.id
              WHERE oi.supplier_id = ? AND oi.side = ? AND oi.status IN ('confirmed', 'posted')
                AND oi.deleted_at IS NULL AND oi.currency = 'CZK'
                AND {$remaining} > 0.005{$where}
              ORDER BY ABS({$remaining} - ?) <= ? DESC, oi.due_on DESC, oi.id DESC
              LIMIT {$limit}"
        );
        // Shoda částky první — stejný VS mívá u jednoho partnera víc položek.
        array_push($params, $free, self::AMOUNT_TOLERANCE);
        $stmt->execute($params);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'type' => 'other_item',
                'id' => (int) $row['id'],
                'ref' => ($row['document_no'] ?? '') !== '' ? (string) $row['document_no'] : null,
                'amount' => round((float) $row['remaining_amount'], 2),
                'currency' => 'CZK',
                'converted_amount' => null,
                'converted_currency' => null,
                'issue_date' => (string) $row['issued_on'],
                'due_date' => $row['due_on'] !== null ? (string) $row['due_on'] : null,
                'party' => ($row['partner_name'] ?? '') !== '' ? (string) $row['partner_name'] : (string) $row['title'],
                'paid' => false,
                'currency_mismatch' => false,
                'side' => (string) $row['side'],
            ];
        }
        return $rows;
    }

    /**
     * Přiřadí pohyb položce. Nezaúčtovaný pohyb nejdřív zaúčtuje proti účtu položky;
     * částka je volná část pohybu, nanejvýš zbývající dluh (víc nepřiřadí, přeplatek
     * odmítne allocate() i u kandidáta bez shody částky).
     *
     * @param array<string,mixed> $meta auditní údaje pro ruční zápis pohybu
     * @param bool $mayPost volající smí zapisovat do účetnictví (zaúčtování pohybu)
     */
    public function match(int $supplierId, int $txId, int $itemId, ?int $userId, array $meta, bool $mayPost): array
    {
        $pdo = $this->db->pdo();
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) $pdo->beginTransaction();
        try {
            $tx = $this->freeTransaction($supplierId, $txId, true)
                ?? throw new OtherItemException('payment_unavailable',
                    'Platbu nelze přiřadit: je už spárovaná, vyčerpaná nebo není v CZK.', 409);
            if ($tx['locked_item_id'] !== null && (int) $tx['locked_item_id'] !== $itemId) {
                throw new OtherItemException('payment_reallocation_conflict',
                    'Po stornu lze platbu znovu přiřadit jen k původní položce.', 409);
            }
            $item = $this->items->get($supplierId, $itemId);
            $side = (float) $tx['amount'] > 0 ? 'receivable' : 'payable';
            if ($item['side'] !== $side) {
                throw new OtherItemException('payment_direction', $side === 'receivable'
                    ? 'Příchozí platbu lze přiřadit jen pohledávce.'
                    : 'Odchozí platbu lze přiřadit jen závazku.', 409);
            }
            $amount = round(min(abs((float) $tx['amount']) - (float) $tx['used_amount'],
                (float) $item['remaining_amount']), 2);
            if ($amount <= 0) {
                throw new OtherItemException('overpayment', 'Položka už nemá co uhradit.', 409);
            }
            if (abs((float) $tx['amount']) - (float) $tx['used_amount'] - $amount > self::AMOUNT_TOLERANCE
                && !$this->hasLiveEntry($supplierId, $txId)) {
                // Zaúčtovat celý pohyb proti účtu položky by vytvořilo přeplatek na saldokontu.
                throw new OtherItemException('payment_exceeds_item',
                    'Platba je vyšší než zbývající dluh. Zaúčtujte ji rozúčtováním a položce ji přiřaďte z jejího detailu.', 409);
            }
            $account = $this->items->settlementAccountCode($supplierId, $item);
            if ($account !== null && !$this->hasLiveEntry($supplierId, $txId)) {
                if (!$mayPost) {
                    throw new OtherItemException('posting_forbidden',
                        'Platbu je potřeba zaúčtovat a k tomu chybí oprávnění k účetnictví.', 403);
                }
                $this->bankPosting->postManual($supplierId, $txId, $side === 'receivable'
                    ? ['debit_account_code' => '221', 'credit_account_code' => $account]
                    : ['debit_account_code' => $account, 'credit_account_code' => '221'], $meta);
            }
            $result = $this->items->allocate($supplierId, $itemId,
                ['bank_transaction_id' => $txId, 'amount' => $amount], $userId);
            if ($ownTx) $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Pohyb firmy volný k přiřazení ({@see OtherItemService::freeBankPaymentSql}) s jeho
     * již přiřazenou částkou; `locked_item_id` je položka, ke které se smí vrátit po stornu.
     *
     * @return array{id:int,amount:float,variable_symbol:?string,used_amount:float,locked_item_id:?int}|null
     */
    private function freeTransaction(int $supplierId, int $txId, bool $lock): ?array
    {
        if ($lock && $this->bankPosting->lockTransaction($supplierId, $txId) === null) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT bt.id, bt.amount, bt.variable_symbol, COALESCE(a.used_amount, 0) used_amount,
                    (SELECT MIN(previous.other_item_id) FROM other_item_allocations previous
                      WHERE previous.supplier_id = bs.supplier_id AND previous.bank_transaction_id = bt.id
                        AND previous.reversed_on IS NOT NULL) locked_item_id
               FROM bank_transactions bt
               JOIN bank_statements bs ON bs.id = bt.statement_id AND bs.supplier_id = ?
          LEFT JOIN (SELECT bank_transaction_id, SUM(amount) used_amount FROM other_item_allocations
                      WHERE supplier_id = ? AND bank_transaction_id = ? AND reversed_on IS NULL
                      GROUP BY bank_transaction_id) a
                 ON a.bank_transaction_id = bt.id
              WHERE bt.id = ? AND bt.amount <> 0
                AND ' . OtherItemService::freeBankPaymentSql()
        );
        $stmt->execute([$supplierId, $supplierId, $txId, $txId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;
        return [
            'id' => (int) $row['id'],
            'amount' => (float) $row['amount'],
            'variable_symbol' => $row['variable_symbol'] !== null ? (string) $row['variable_symbol'] : null,
            'used_amount' => (float) $row['used_amount'],
            'locked_item_id' => $row['locked_item_id'] !== null ? (int) $row['locked_item_id'] : null,
        ];
    }

    private function hasLiveEntry(int $supplierId, int $txId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT 1 FROM journal_entries WHERE supplier_id = ? AND source_type = 'bank' AND source_id = ?
                AND reversed_by IS NULL LIMIT 1"
        );
        $stmt->execute([$supplierId, $txId]);
        return $stmt->fetchColumn() !== false;
    }
}
