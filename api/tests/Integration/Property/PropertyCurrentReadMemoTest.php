<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Property;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\LoggingPdo;
use MyInvoice\Repository\AccountDefinitionRepository;
use MyInvoice\Repository\AccountingPeriodRepository;
use MyInvoice\Repository\ChartOfAccountsRepository;
use MyInvoice\Service\Accounting\ChartOfAccountsSeeder;
use MyInvoice\Service\Accounting\PostingService;
use MyInvoice\Service\Property\Payments\PaymentJournalProof;
use MyInvoice\Tests\Support\IsolatedSupplierTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Důkazy agendy SVJ čtou tentýž zamčený zápis a tutéž osnovu desítkykrát za operaci.
 * Aktuální čtení se v jedné transakci opakuje jen po zápisu, savepointu nebo hranici
 * transakce; vlastní změnu i návrat k savepointu vidí hned.
 */
#[Group('integration')]
final class PropertyCurrentReadMemoTest extends TestCase
{
    use IsolatedSupplierTrait;

    private Connection $db;
    private int $supplier;
    private int $entry;

    protected function setUp(): void
    {
        $container = Bootstrap::buildContainer();
        $this->db = $container->get(Connection::class);
        $pdo = $this->db->pdo();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertInstanceOf(LoggingPdo::class, $pdo);
        $pdo->beginTransaction();
        $this->supplier = $this->createIsolatedSupplier($pdo, (int) $pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn());
        (new AccountingPeriodRepository($this->db))->create($this->supplier, 2026, '2026-01-01', '2026-12-31');
        (new ChartOfAccountsSeeder($this->db))->seedForSupplier($this->supplier, '2026-01-01');
        $actor = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
        $this->entry = $container->get(PostingService::class)->postDocument($this->supplier, 'manual', null, [
            ['account_code' => '221', 'side' => 'debit', 'amount' => '100.00'],
            ['account_code' => '321', 'side' => 'credit', 'amount' => '100.00'],
        ], ['entry_date' => '2026-03-01', 'posted_by' => $actor]);
    }

    protected function tearDown(): void
    {
        if ($this->db->pdo()->inTransaction()) $this->db->pdo()->rollBack();
    }

    public function testJournalProofIsReadOnceUntilOwnChange(): void
    {
        $proof = new PaymentJournalProof($this->db);
        $first = $proof->current($this->supplier, $this->entry);
        self::assertSame(0, $this->queriesDuring(fn () => self::assertSame($first, (new PaymentJournalProof($this->db))->current($this->supplier, $this->entry))),
            'Opakované aktuální čtení zápisu bez změny nemá posílat dotazy.');
        self::assertGreaterThan(0, $this->queriesDuring(fn () => $proof->snapshot($this->supplier, $this->entry)), 'Čtení bez zámků se nepamatuje.');

        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT journal_memo');
        $pdo->prepare('UPDATE journal_entry_lines SET amount = 150 WHERE supplier_id = ? AND entry_id = ?')->execute([$this->supplier, $this->entry]);
        self::assertSame(['150.00', '150.00'], array_column($proof->current($this->supplier, $this->entry)['lines'], 'amount'));
        $pdo->exec('ROLLBACK TO SAVEPOINT journal_memo');
        self::assertSame($first, $proof->current($this->supplier, $this->entry));
    }

    public function testCurrentChartIsReadOnceUntilChartChanges(): void
    {
        $definitions = new AccountDefinitionRepository($this->db);
        $codes = array_column($definitions->currentForDate($this->supplier, '2026-03-01'), 'account_code');
        self::assertContains('221', $codes);
        self::assertSame(0, $this->queriesDuring(fn () => (new AccountDefinitionRepository($this->db))->currentForDate($this->supplier, '2026-03-01')));
        self::assertGreaterThan(0, $this->queriesDuring(fn () => $definitions->currentForDate($this->supplier, '2026-03-02')), 'Jiné datum je jiná osnova.');

        $chart = new ChartOfAccountsRepository($this->db);
        $parent = $chart->findByCode($this->supplier, '221');
        $chart->insert($this->supplier, ['account_code' => '221.900', 'name' => 'Synthetic bank analytic', 'account_type' => $parent['account_type'],
            'normal_side' => $parent['normal_side'], 'parent_id' => (int) $parent['id']]);
        self::assertContains('221.900', array_column($definitions->currentForDate($this->supplier, '2026-03-01'), 'account_code'));
    }

    private function queriesDuring(callable $work): int
    {
        $pdo = $this->db->pdo();
        $count = static fn (): int => (int) $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(\PDO::FETCH_NUM)[1];
        $before = $count();
        $work();
        return $count() - $before - 1;
    }
}
