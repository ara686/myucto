<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\OtherItemBankMatchService;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Bank\OtherItemPaymentMatchReader;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Párování bankovního pohybu s ostatní pohledávkou / závazkem z dialogu „Spárovat"
 * u pohybu. Syntetická firma, syntetický účet 1000000005/0100, období 2099.
 */
#[Group('integration')]
final class OtherItemBankMatchServiceTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private PDO $pdo;
    private OtherItemService $items;
    private OtherItemBankMatchService $service;
    private int $supplierId;
    private int $statementId;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $this->items = $container->get(OtherItemService::class);
        $this->service = $container->get(OtherItemBankMatchService::class);
        $this->pdo = $this->db->pdo();
        $this->pdo->beginTransaction();
        $source = (int) $this->pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
        self::assertGreaterThan(0, $source);
        $this->supplierId = $this->createIsolatedSupplier($this->pdo, $source);
        $this->pdo->prepare("UPDATE supplier SET accounting_mode = 'double_entry' WHERE id = ?")
            ->execute([$this->supplierId]);
        $container->get(ChartOfAccountsSeeder::class)->seedForSupplier($this->supplierId);
        $container->get(AccountingPeriodRepository::class)->create($this->supplierId, 2099, '2099-01-01', '2099-12-31');
        $this->pdo->prepare(
            'INSERT INTO bank_statements (supplier_id, file_name, file_hash, account_number, bank_code, statement_date, currency)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->supplierId, 'synteticky-vypis', hash('sha256', uniqid('', true)),
            '1000000005', '0100', '2099-01-20', 'CZK']);
        $this->statementId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) $this->pdo->rollBack();
        if (isset($this->db)) $this->db->close();
    }

    public function testIncomingPaymentIsOfferedPostedAgainstReceivableAndAllocated(): void
    {
        $item = $this->postedItem(['side' => 'receivable', 'kind' => 'claim', 'title' => 'Syntetická pohledávka',
            'counter_account_code' => '602']);
        $other = $this->postedItem(['side' => 'receivable', 'kind' => 'claim', 'title' => 'Jiná pohledávka',
            'counter_account_code' => '602', 'amount' => 999]);
        $txId = $this->transaction(1200);

        $candidates = $this->service->candidates($this->supplierId, $txId);
        self::assertSame([$item], array_column($candidates, 'id'), 'Bez hledání jen shoda částky nebo VS.');
        self::assertSame('other_item', $candidates[0]['type']);
        self::assertSame('receivable', $candidates[0]['side']);
        self::assertEqualsWithDelta(1200.0, $candidates[0]['amount'], 0.001);
        self::assertContains($other, array_column(
            $this->service->candidates($this->supplierId, $txId, 'Jiná'), 'id'), 'Hledání ignoruje částku.');
        self::assertSame([], $this->service->candidates($this->supplierId, $this->transaction(-1200)),
            'Odchozí platba pohledávku nenabízí.');

        $result = $this->service->match($this->supplierId, $txId, $item, null, [], true);
        self::assertEqualsWithDelta(0.0, (float) $result['remaining_amount'], 0.001);
        self::assertCount(1, $this->items->allocations($this->supplierId, $item));
        self::assertContains('315:credit:1200.00', $this->bankLines($txId));
        $linked = OtherItemPaymentMatchReader::byTransactions($this->pdo, $this->supplierId, [$txId]);
        self::assertSame([$item], array_column($linked[$txId] ?? [], 'other_item_id'), 'Řádek pohybu ukáže navázanou položku.');
        self::assertSame([], OtherItemPaymentMatchReader::byTransactions($this->pdo, $this->supplierId + 100000, [$txId]));
        self::assertSame([], $this->service->candidates($this->supplierId, $txId),
            'Plně přiřazený pohyb už nic nenabízí.');
    }

    public function testAmountMatchIsListedBeforeSharedVariableSymbol(): void
    {
        $first = $this->postedItem(['variable_symbol' => '2099001', 'amount' => 1200, 'due_on' => '2099-01-10']);
        $later = $this->postedItem(['variable_symbol' => '2099001', 'amount' => 300, 'due_on' => '2099-01-30']);
        $this->pdo->prepare('UPDATE bank_transactions SET variable_symbol = ? WHERE id = ?')
            ->execute(['2099001', $txId = $this->transaction(-1200)]);
        self::assertSame([$first, $later], array_column($this->service->candidates($this->supplierId, $txId), 'id'));
    }

    public function testPaymentDirectionMustMatchItemSide(): void
    {
        $item = $this->postedItem(['side' => 'receivable', 'kind' => 'claim', 'title' => 'Syntetická pohledávka',
            'counter_account_code' => '602']);
        try {
            $this->service->match($this->supplierId, $this->transaction(-1200), $item, null, [], true);
            self::fail('Odchozí platba nesmí uhradit pohledávku.');
        } catch (OtherItemException $e) {
            self::assertSame('payment_direction', $e->errorCode);
        }
        self::assertCount(0, $this->items->allocations($this->supplierId, $item));
    }

    public function testUnpostedPaymentNeedsAccountingPermission(): void
    {
        $item = $this->postedItem();
        $txId = $this->transaction(-1200);
        try {
            $this->service->match($this->supplierId, $txId, $item, null, [], false);
            self::fail('Bez práva k účetnictví se pohyb nesmí zaúčtovat.');
        } catch (OtherItemException $e) {
            self::assertSame('posting_forbidden', $e->errorCode);
        }
        self::assertSame([], $this->bankLines($txId));
        self::assertCount(0, $this->items->allocations($this->supplierId, $item));

        $this->service->match($this->supplierId, $txId, $item, null, [], true);
        self::assertContains('325:debit:1200.00', $this->bankLines($txId));
    }

    public function testUnpostedPaymentLargerThanDebtIsRejected(): void
    {
        $item = $this->postedItem();
        $txId = $this->transaction(-1500);
        self::assertContains($item, array_column(
            $this->service->candidates($this->supplierId, $txId, 'Syntetické'), 'id'));
        try {
            $this->service->match($this->supplierId, $txId, $item, null, [], true);
            self::fail('Celý pohyb proti závazku by vytvořil přeplatek na saldokontu.');
        } catch (OtherItemException $e) {
            self::assertSame('payment_exceeds_item', $e->errorCode);
        }
        self::assertSame([], $this->bankLines($txId));
    }

    public function testPartialPaymentLeavesRemainingDebt(): void
    {
        $item = $this->postedItem();
        $txId = $this->transaction(-500);
        $result = $this->service->match($this->supplierId, $txId, $item, null, [], true);
        self::assertEqualsWithDelta(700.0, (float) $result['remaining_amount'], 0.001);
        self::assertContains('325:debit:500.00', $this->bankLines($txId));
    }

    /** @param array<string,mixed> $changes */
    private function postedItem(array $changes = []): int
    {
        $draft = $this->items->create($this->supplierId, array_replace([
            'side' => 'payable', 'kind' => 'rent', 'title' => 'Syntetické nájemné',
            'issued_on' => '2099-01-01', 'accounting_on' => '2099-01-01',
            'due_on' => '2099-01-20', 'currency' => 'CZK', 'amount' => 1200,
            'counter_account_code' => '518',
        ], $changes), null);
        $this->items->post($this->supplierId, (int) $draft['id'], null);
        return (int) $draft['id'];
    }

    private function transaction(float $amount): int
    {
        $this->pdo->prepare(
            'INSERT INTO bank_transactions
                (statement_id, posted_at, amount, currency, match_status, counterparty_name, description)
             VALUES (?, ?, ?, ?, "unmatched", "Syntetický partner", "Syntetická platba")'
        )->execute([$this->statementId, '2099-01-20', $amount, 'CZK']);
        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<string> řádky živého bankovního zápisu jako „účet:strana:částka" (bez analytiky) */
    private function bankLines(int $txId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT CONCAT(SUBSTRING_INDEX(a.account_code, '.', 1), ':', l.side, ':', CAST(l.amount AS CHAR))
               FROM journal_entries e
               JOIN journal_entry_lines l ON l.entry_id = e.id
               JOIN chart_of_accounts a ON a.id = l.account_id
              WHERE e.supplier_id = ? AND e.source_type = 'bank' AND e.source_id = ? AND e.reversed_by IS NULL
              ORDER BY l.id"
        );
        $stmt->execute([$this->supplierId, $txId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
