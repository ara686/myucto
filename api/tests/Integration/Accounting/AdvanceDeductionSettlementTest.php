<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Accounting;

use MyInvoice\Action\Accounting\JournalSettlementAction;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use MyInvoice\Service\Accounting\AdvanceDeductionService;
use MyInvoice\Service\Accounting\JournalSettlementException;
use MyInvoice\Service\Accounting\JournalSettlementService;
use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Tests\Integration\Accounting\Bank\BankPostingTestCase;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * Vyrovnání zálohy na 314/324 v saldokontu čerpáním konečnou fakturou
 * (`purchase_advance_deductions` / `invoice_advance_deductions`, migrace 2190 a 2195).
 *
 * Firma testu má v deníku i jiná data, proto se konfrontace s hlavní knihou měří rozdílem
 * před a po: čerpání nemění zůstatek účtu, jen součet otevřených položek.
 */
#[Group('integration')]
final class AdvanceDeductionSettlementTest extends BankPostingTestCase
{
    private AdvanceDeductionService $advances;
    private SaldoService $saldo;
    private int $statementId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->advances = $this->container->get(AdvanceDeductionService::class);
        $this->saldo = $this->container->get(SaldoService::class);
    }

    public function testPurchaseDeductionClosesAdvanceAndLowersDifferenceWithoutTouchingJournal(): void
    {
        $vendor = $this->client('Syntetický dodavatel zálohy');
        $advance = $this->paidPurchaseAdvance('ZF-SYN-1', $vendor, 1000.0);
        $final = $this->purchaseFinal('FA-SYN-1', $vendor, 1210.0, 1000.0);
        $before = $this->block('314');
        self::assertEqualsWithDelta(1000.0, $this->remaining($before, 'purchase_invoice', $advance), 0.001);
        self::assertTrue($this->item($before, 'purchase_invoice', $advance)['settleable']);
        $journal = $this->journalCount();

        $candidates = $this->advances->candidates($this->supplierId, 'purchase_invoice', $advance, '314', self::YEAR . '-12-31');
        self::assertTrue($candidates['document']['supported']);
        self::assertSame($final, $candidates['items'][0]['doc_id']);
        self::assertTrue($candidates['items'][0]['amount_match']);
        self::assertEqualsWithDelta(1000.0, $candidates['items'][0]['unassigned'], 0.001);

        $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $advance, 'final_id' => $final, 'amount' => 1000.0],
        ], $this->userId);

        $after = $this->block('314');
        self::assertNull($this->remaining($after, 'purchase_invoice', $advance));
        self::assertEqualsWithDelta($before['gl_balance'], $after['gl_balance'], 0.001);
        self::assertEqualsWithDelta($before['difference'] + 1000.0, $after['difference'], 0.001, 'Čerpání sníží rozdíl k hlavní knize o čerpanou částku.');
        self::assertSame($journal, $this->journalCount(), 'Čerpání nevytváří zápis v deníku.');
        // Před zaúčtováním konečné faktury čerpání neplatí.
        self::assertEqualsWithDelta(1000.0, $this->remaining($this->block('314', self::YEAR . '-06-30'), 'purchase_invoice', $advance), 0.001);
        self::assertSame('saldo', $this->db->pdo()->query("SELECT source FROM purchase_advance_deductions WHERE final_invoice_id = {$final}")->fetchColumn());
    }

    public function testOneFinalSplitsAcrossAdvancesAndOneAdvanceAcrossFinals(): void
    {
        $vendor = $this->client('Syntetický dodavatel rozdělení');
        $a1 = $this->paidPurchaseAdvance('ZF-SPL-1', $vendor, 600.0);
        $a2 = $this->paidPurchaseAdvance('ZF-SPL-2', $vendor, 400.0);
        $f = $this->purchaseFinal('FA-SPL-1', $vendor, 1500.0, 1000.0);
        $a3 = $this->paidPurchaseAdvance('ZF-SPL-3', $vendor, 1000.0);
        $f1 = $this->purchaseFinal('FA-SPL-2', $vendor, 500.0, 400.0);
        $f2 = $this->purchaseFinal('FA-SPL-3', $vendor, 800.0, 600.0);

        $this->assertDeductionError('final_overdeducted', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $a1, 'final_id' => $f1, 'amount' => 500.0],
        ], $this->userId));
        $this->assertDeductionError('advance_overdeducted', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $a2, 'final_id' => $f, 'amount' => 400.01],
        ], $this->userId));

        $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $a1, 'final_id' => $f, 'amount' => 600.0],
            ['doc_type' => 'purchase_invoice', 'advance_id' => $a2, 'final_id' => $f, 'amount' => 400.0],
        ], $this->userId);
        $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $a3, 'final_id' => $f1, 'amount' => 400.0],
        ], $this->userId);
        self::assertEqualsWithDelta(600.0, $this->remaining($this->block('314'), 'purchase_invoice', $a3), 0.001);
        // Druhé čerpání téže dvojice se přičte, nevznikne druhý řádek.
        $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $a3, 'final_id' => $f2, 'amount' => 200.0],
        ], $this->userId);
        $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $a3, 'final_id' => $f2, 'amount' => 400.0],
        ], $this->userId);

        $block = $this->block('314');
        foreach ([$a1, $a2, $a3] as $id) {
            self::assertNull($this->remaining($block, 'purchase_invoice', $id));
        }
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM purchase_advance_deductions WHERE final_invoice_id = {$f2}")->fetchColumn());
        $this->assertDeductionError('final_overdeducted', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $a3, 'final_id' => $f, 'amount' => 0.01],
        ], $this->userId));
        $spare = $this->purchaseFinal('FA-SPL-4', $vendor, 200.0, 100.0);
        $this->assertDeductionError('advance_not_open', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $a3, 'final_id' => $spare, 'amount' => 0.01],
        ], $this->userId));
    }

    public function testExistingLinksAndImportedDeductionsAreNotCountedTwice(): void
    {
        $vendor = $this->client('Syntetický dodavatel vazeb');
        // Vyúčtovací faktura navázaná na zálohu přes advance_purchase_invoice_id: celý odpočet
        // patří té záloze, jiné ho nabídnout nelze.
        $linkedAdvance = $this->paidPurchaseAdvance('ZF-LNK-1', $vendor, 500.0);
        $linkedFinal = $this->purchaseFinal('FA-LNK-1', $vendor, 600.0, 500.0);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ?')->execute([$linkedAdvance, $linkedFinal]);
        // Čerpání převzaté z převodu.
        $importedAdvance = $this->paidPurchaseAdvance('ZF-IMP-1', $vendor, 500.0);
        $importedFinal = $this->purchaseFinal('FA-IMP-1', $vendor, 1200.0, 1000.0);
        $this->db->pdo()->prepare('INSERT INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount) VALUES (?, ?, ?, 400)')
            ->execute([$this->supplierId, $importedFinal, $importedAdvance]);
        // Daňový doklad k platbě zálohy (rodič = záloha) čerpá DPH zálohy, konečnou fakturou není.
        $open = $this->paidPurchaseAdvance('ZF-OPN-1', $vendor, 1210.0);
        $ddkp = $this->purchaseInvoice('DD-OPN-1', $vendor, 210.0, 'tax_document');
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')->execute([$open, $ddkp]);
        $this->postEntry('purchase_invoice', $ddkp, self::YEAR . '-07-15', [['343', 'debit', 210.0], ['314', 'credit', 210.0]]);

        $block = $this->block('314');
        self::assertNull($this->remaining($block, 'purchase_invoice', $linkedAdvance));
        self::assertEqualsWithDelta(100.0, $this->remaining($block, 'purchase_invoice', $importedAdvance), 0.001);
        self::assertEqualsWithDelta(1000.0, $this->remaining($block, 'purchase_invoice', $open), 0.001);

        $items = $this->advances->candidates($this->supplierId, 'purchase_invoice', $open, '314', self::YEAR . '-12-31')['items'];
        $byId = array_column($items, null, 'doc_id');
        self::assertArrayNotHasKey($linkedFinal, $byId, 'Odpočet navázané vyúčtovací faktury patří její záloze.');
        self::assertArrayNotHasKey($ddkp, $byId, 'Daňový doklad k platbě není konečná faktura.');
        self::assertEqualsWithDelta(600.0, $byId[$importedFinal]['unassigned'], 0.001);
        self::assertEqualsWithDelta(400.0, $byId[$importedFinal]['deducted'], 0.001);

        $this->assertDeductionError('final_overdeducted', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $open, 'final_id' => $linkedFinal, 'amount' => 1.0],
        ], $this->userId));
        $this->assertDeductionError('final_overdeducted', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $open, 'final_id' => $importedFinal, 'amount' => 600.01],
        ], $this->userId));
        $this->assertDeductionError('pair_imported', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $importedAdvance, 'final_id' => $importedFinal, 'amount' => 1.0],
        ], $this->userId));

        $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $open, 'final_id' => $importedFinal, 'amount' => 600.0],
        ], $this->userId);
        self::assertEqualsWithDelta(400.0, $this->remaining($this->block('314'), 'purchase_invoice', $open), 0.001);
        $importedId = (int) $this->db->pdo()->query("SELECT id FROM purchase_advance_deductions WHERE final_invoice_id = {$importedFinal} AND advance_invoice_id = {$importedAdvance}")->fetchColumn();
        $this->assertDeductionError('deduction_imported', fn () => $this->advances->delete($this->supplierId, 'purchase_invoice', $importedId));
    }

    public function testValidationRejectsOtherPartnerUnpostedForeignTenantAndCurrency(): void
    {
        $vendor = $this->client('Syntetický dodavatel validace');
        $other = $this->client('Syntetický jiný dodavatel');
        $advance = $this->paidPurchaseAdvance('ZF-VAL-1', $vendor, 1000.0);
        $foreignPartner = $this->purchaseFinal('FA-VAL-1', $other, 1000.0, 1000.0);
        $unposted = $this->purchaseInvoice('FA-VAL-2', $vendor, 1000.0);
        $final = $this->purchaseFinal('FA-VAL-3', $vendor, 1000.0, 1000.0);

        $this->assertDeductionError('partner_mismatch', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $advance, 'final_id' => $foreignPartner, 'amount' => 100.0],
        ], $this->userId));
        $this->assertDeductionError('final_not_posted', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $advance, 'final_id' => $unposted, 'amount' => 100.0],
        ], $this->userId));
        $this->assertDeductionError('invalid_amount', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $advance, 'final_id' => $final, 'amount' => 0.001],
        ], $this->userId));
        $this->assertDeductionError('invalid_deductions', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $advance, 'final_id' => $advance, 'amount' => 1.0],
        ], $this->userId));
        $this->assertDeductionError('invalid_doc_type', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'other_item', 'advance_id' => $advance, 'final_id' => $final, 'amount' => 1.0],
        ], $this->userId));

        // Izolace firem: doklady jiné firmy jsou pro tuto firmu neexistující.
        $foreignSupplier = $this->otherSupplierId();
        $this->assertDeductionError('not_found', fn () => $this->advances->deduct($foreignSupplier, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $advance, 'final_id' => $final, 'amount' => 100.0],
        ], $this->userId));
        $this->assertDeductionError('not_found', fn () => $this->advances->candidates($foreignSupplier, 'purchase_invoice', $advance, '314', self::YEAR . '-12-31'));

        $eur = $this->currencyRow($this->supplierId, 'EUR');
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET currency_id = ? WHERE id = ?')->execute([$eur, $final]);
        $this->assertDeductionError('document_currency', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'purchase_invoice', 'advance_id' => $advance, 'final_id' => $final, 'amount' => 100.0],
        ], $this->userId));
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM purchase_advance_deductions WHERE advance_invoice_id = {$advance}")->fetchColumn());
    }

    public function testReceivedAdvanceOn324IsSettledByFinalInvoicesIncludingSeveralAdvancesOnOneInvoice(): void
    {
        $client = $this->client('Syntetický odběratel zálohy');
        $p1 = $this->paidProforma('ZAL-SYN-1', $client, 700.0);
        $p2 = $this->paidProforma('ZAL-SYN-2', $client, 300.0);
        $final = $this->saleFinal('FV-SYN-1', $client, 1210.0, 1000.0);
        // Konečná faktura navázaná na proformu přes parent_invoice_id čerpá jen ji.
        $p3 = $this->paidProforma('ZAL-SYN-3', $client, 500.0);
        $linked = $this->saleFinal('FV-SYN-2', $client, 605.0, 500.0);
        $this->db->pdo()->prepare('UPDATE invoices SET parent_invoice_id = ? WHERE id = ?')->execute([$p3, $linked]);

        $before = $this->block('324');
        self::assertEqualsWithDelta(700.0, $this->remaining($before, 'invoice', $p1), 0.001);
        self::assertNull($this->remaining($before, 'invoice', $p3));
        $items = $this->advances->candidates($this->supplierId, 'invoice', $p1, '324', self::YEAR . '-12-31')['items'];
        self::assertSame([$final], array_column($items, 'doc_id'));

        $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'invoice', 'advance_id' => $p1, 'final_id' => $final, 'amount' => 700.0],
            ['doc_type' => 'invoice', 'advance_id' => $p2, 'final_id' => $final, 'amount' => 300.0],
        ], $this->userId);
        $after = $this->block('324');
        self::assertNull($this->remaining($after, 'invoice', $p1));
        self::assertNull($this->remaining($after, 'invoice', $p2));
        self::assertEqualsWithDelta($before['difference'] + 1000.0, $after['difference'], 0.001, 'Čerpání sníží rozdíl k hlavní knize o čerpanou částku.');
        self::assertEqualsWithDelta($before['gl_balance'], $after['gl_balance'], 0.001);
        $this->assertDeductionError('final_overdeducted', fn () => $this->advances->deduct($this->supplierId, [
            ['doc_type' => 'invoice', 'advance_id' => $p1, 'final_id' => $linked, 'amount' => 1.0],
        ], $this->userId));
        self::assertSame(2, (int) $this->db->pdo()->query("SELECT COUNT(*) FROM invoice_advance_deductions WHERE final_invoice_id = {$final} AND source = 'saldo'")->fetchColumn());
    }

    public function testSuggestionsProposeCertainAdvanceMatchesOnly(): void
    {
        $vendor = $this->client('Syntetický dodavatel návrhu');
        // Číslo zálohy v popisu řádku konečné faktury.
        $named = $this->paidPurchaseAdvance('ZFSUG001', $vendor, 300.0);
        $namedFinal = $this->purchaseFinal('FA-SUG-1', $vendor, 900.0, 800.0, 'Odpočet zálohy ZFSUG001');
        // Pravidelné zálohy stejné výše: jistá je faktura mezi zálohou a další zálohou.
        $m1 = $this->paidPurchaseAdvance('ZF-MES-1', $vendor, 250.0, self::YEAR . '-02-01');
        $m2 = $this->paidPurchaseAdvance('ZF-MES-2', $vendor, 250.0, self::YEAR . '-03-01');
        $f1 = $this->purchaseFinal('FA-MES-1', $vendor, 400.0, 250.0, null, self::YEAR . '-02-10');
        $f2 = $this->purchaseFinal('FA-MES-2', $vendor, 400.0, 250.0, null, self::YEAR . '-03-10');
        // Dva kandidáti se stejnou částkou bez pořadí: nejisté, nenavrhne se.
        $other = $this->client('Syntetický dodavatel nejistý');
        $ambiguous = $this->paidPurchaseAdvance('ZF-AMB-1', $other, 100.0, self::YEAR . '-08-01');
        $this->purchaseFinal('FA-AMB-1', $other, 150.0, 100.0, null, self::YEAR . '-07-10');
        $this->purchaseFinal('FA-AMB-2', $other, 150.0, 100.0, null, self::YEAR . '-07-12');

        $suggestions = $this->container->get(JournalSettlementService::class)->suggestions($this->supplierId, $this->periodId, self::YEAR . '-12-31', '314');
        $advanceItems = array_values(array_filter($suggestions['items'], static fn (array $s): bool => ($s['type'] ?? '') === 'advance'));
        $byAdvance = array_column(array_map(static fn (array $s): array => [
            'advance' => $s['advance']['doc_id'], 'final' => $s['final']['doc_id'], 'reason' => $s['reason'], 'amount' => $s['amount'],
        ], $advanceItems), null, 'advance');

        self::assertSame(['advance' => $named, 'final' => $namedFinal, 'reason' => 'advance_doc_no', 'amount' => 300.0], $byAdvance[$named] ?? null);
        self::assertSame($f1, $byAdvance[$m1]['final'] ?? null);
        self::assertSame('advance_sequence', $byAdvance[$m1]['reason'] ?? null);
        self::assertSame($f2, $byAdvance[$m2]['final'] ?? null);
        self::assertArrayNotHasKey($ambiguous, $byAdvance);

        // Potvrzení návrhů přes akci po skupinách.
        $action = $this->container->get(JournalSettlementAction::class);
        $groups = array_map(static fn (array $s): array => ['deductions' => [[
            'doc_type' => 'purchase_invoice', 'advance_id' => $s['advance']['doc_id'], 'final_id' => $s['final']['doc_id'], 'amount' => $s['amount'],
        ]]], array_values(array_filter($advanceItems, static fn (array $s): bool => in_array($s['advance']['doc_id'], [$named, $m1, $m2], true))));
        $result = $this->callAction($action, 'createAdvanceDeductions', 'POST', 'accountant', ['groups' => $groups]);
        self::assertSame(201, $result['status'], json_encode($result['body']));
        self::assertCount(3, $result['body']['created']);
        $block = $this->block('314');
        foreach ([$named, $m1, $m2] as $id) {
            self::assertNull($this->remaining($block, 'purchase_invoice', $id));
        }
        $again = $this->container->get(JournalSettlementService::class)->suggestions($this->supplierId, $this->periodId, self::YEAR . '-12-31', '314');
        self::assertSame([], array_values(array_filter($again['items'], static fn (array $s): bool => in_array($s['advance']['doc_id'] ?? 0, [$named, $m1, $m2], true))));
    }

    public function testActionGuardsClosedPeriodAuditsAndDeletes(): void
    {
        $vendor = $this->client('Syntetický dodavatel akce');
        $advance = $this->paidPurchaseAdvance('ZF-ACT-1', $vendor, 800.0);
        $final = $this->purchaseFinal('FA-ACT-1', $vendor, 1000.0, 800.0);
        $body = ['deductions' => [['doc_type' => 'purchase_invoice', 'advance_id' => $advance, 'final_id' => $final, 'amount' => 800.0]]];
        $action = $this->container->get(JournalSettlementAction::class);

        $candidates = $this->callActionQuery($action, 'advanceCandidates', 'accountant', [
            'doc_type' => 'purchase_invoice', 'doc_id' => (string) $advance, 'account' => '314', 'as_of' => self::YEAR . '-12-31',
        ]);
        self::assertSame(200, $candidates['status']);
        self::assertSame($final, $candidates['body']['items'][0]['doc_id'] ?? null);

        self::assertSame(403, $this->callAction($action, 'createAdvanceDeductions', 'POST', 'readonly', $body)['status']);

        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'closed' WHERE id = ? AND supplier_id = ?")
            ->execute([$this->periodId, $this->supplierId]);
        $closed = $this->callAction($action, 'createAdvanceDeductions', 'POST', 'accountant', $body);
        self::assertSame(409, $closed['status']);
        self::assertSame('period_closed', $closed['body']['error']['code'] ?? null);
        self::assertNotNull($this->remaining($this->block('314'), 'purchase_invoice', $advance));

        $forced = $this->forced($action, 'createAdvanceDeductions', 'POST', $body);
        self::assertSame(201, $forced['status'], json_encode($forced['body']));
        $id = (int) $forced['body']['created'][0]['id'];
        self::assertNull($this->remaining($this->block('314'), 'purchase_invoice', $advance));
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'accounting.saldo_advance_deduction_created' AND supplier_id = {$this->supplierId}"
        )->fetchColumn());

        $deniedDelete = $this->callAction($action, 'deleteAdvanceDeduction', 'DELETE', 'accountant', [], ['doc_type' => 'purchase_invoice', 'id' => (string) $id]);
        self::assertSame(409, $deniedDelete['status']);
        $this->db->pdo()->prepare("UPDATE accounting_periods SET status = 'open' WHERE id = ? AND supplier_id = ?")
            ->execute([$this->periodId, $this->supplierId]);
        $deleted = $this->callAction($action, 'deleteAdvanceDeduction', 'DELETE', 'accountant', [], ['doc_type' => 'purchase_invoice', 'id' => (string) $id]);
        self::assertSame(200, $deleted['status'], json_encode($deleted['body']));
        self::assertEqualsWithDelta(800.0, $this->remaining($this->block('314'), 'purchase_invoice', $advance), 0.001);
        self::assertSame(1, (int) $this->db->pdo()->query(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'accounting.saldo_advance_deduction_deleted' AND supplier_id = {$this->supplierId}"
        )->fetchColumn());
        $missing = $this->callAction($action, 'deleteAdvanceDeduction', 'DELETE', 'accountant', [], ['doc_type' => 'purchase_invoice', 'id' => (string) $id]);
        self::assertSame(404, $missing['status']);
    }

    // ── pomocníci ────────────────────────────────────────────────────────────

    /** Zálohová přijatá faktura zaplacená bankou na 314 (314 MD / 221 D). */
    private function paidPurchaseAdvance(string $number, int $vendor, float $amount, ?string $date = null): int
    {
        $date ??= self::YEAR . '-05-10';
        $id = $this->purchaseInvoice($number, $vendor, $amount, 'advance');
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET varsymbol = ?, issue_date = ?, status = 'paid' WHERE id = ?")->execute([$number, $date, $id]);
        $tx = $this->transaction($this->statementId(), -$amount, ['posted_at' => $date]);
        $this->paymentMatch($tx, $id, $amount);
        $this->postEntry('bank', $tx, $date, [['314', 'debit', $amount], ['221', 'credit', $amount]]);
        return $id;
    }

    /** Konečná přijatá faktura s odpočtem zálohy (321 MD / 314 D). */
    private function purchaseFinal(string $number, int $vendor, float $total, float $deduction, ?string $itemText = null, ?string $date = null): int
    {
        $date ??= self::YEAR . '-07-10';
        $id = $this->purchaseInvoice($number, $vendor, $total);
        $this->db->pdo()->prepare('UPDATE purchase_invoices SET varsymbol = ?, issue_date = ? WHERE id = ?')->execute([$number, $date, $id]);
        if ($itemText !== null) {
            $vatRate = (int) $this->db->pdo()->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn();
            $this->db->pdo()->prepare(
                'INSERT INTO purchase_invoice_items (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id, vat_rate_snapshot,
                    total_without_vat, total_vat, total_with_vat)
                 VALUES (?, ?, 1, "ks", ?, ?, 0, ?, 0, ?)'
            )->execute([$id, $itemText, -$deduction, $vatRate, -$deduction, -$deduction]);
        }
        $this->postEntry('purchase_invoice', $id, $date, [
            ['518', 'debit', $total], ['321', 'credit', $total],
            ['321', 'debit', $deduction], ['314', 'credit', $deduction],
        ]);
        return $id;
    }

    /** Proforma zaplacená bankou na 324 (221 MD / 324 D). */
    private function paidProforma(string $number, int $client, float $amount): int
    {
        $id = $this->saleInvoice($number, $client, $amount, 'proforma', 'paid');
        $tx = $this->transaction($this->statementId(), $amount);
        $this->invoicePayment($id, $tx, $amount);
        $this->postEntry('bank', $tx, self::YEAR . '-06-15', [['221', 'debit', $amount], ['324', 'credit', $amount]]);
        return $id;
    }

    /** Konečná vydaná faktura s odpočtem přijaté zálohy (324 MD / 311 D). */
    private function saleFinal(string $number, int $client, float $total, float $deduction): int
    {
        $id = $this->saleInvoice($number, $client, $total);
        $this->postEntry('invoice', $id, self::YEAR . '-07-10', [
            ['311', 'debit', $total], ['602', 'credit', $total],
            ['324', 'debit', $deduction], ['311', 'credit', $deduction],
        ]);
        return $id;
    }

    /** @param list<array{0:string,1:string,2:float}> $lines [kód účtu, strana, částka] */
    private function postEntry(string $sourceType, int $sourceId, string $date, array $lines): int
    {
        $map = $this->accounts->codeToIdMap($this->supplierId);
        return $this->journal->insert([
            'supplier_id' => $this->supplierId,
            'period_id'   => $this->periodId,
            'entry_date'  => $date,
            'document_no' => 'SYN-' . $sourceType . '-' . $sourceId,
            'description' => 'Syntetický zápis',
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'posted_at'   => date('Y-m-d H:i:s'),
            'posted_by'   => $this->userId,
        ], array_map(static fn (array $l): array => ['account_id' => $map[$l[0]]['id'], 'side' => $l[1], 'amount' => $l[2]], $lines));
    }

    private function statementId(): int
    {
        return $this->statementId > 0 ? $this->statementId : ($this->statementId = $this->statement());
    }

    /** @return array<string,mixed> */
    private function block(string $account, ?string $asOf = null): array
    {
        $report = $this->saldo->build($this->supplierId, $this->periodId, $asOf ?? self::YEAR . '-12-31', $account);
        self::assertCount(1, $report['accounts']);
        return $report['accounts'][0];
    }

    /** @return array<string,mixed>|null */
    private function item(array $block, string $type, int $docId): ?array
    {
        foreach ($block['partners'] as $partner) {
            foreach ($partner['items'] as $item) {
                if ($item['doc_type'] === $type && $item['doc_id'] === $docId) {
                    return $item;
                }
            }
        }
        return null;
    }

    private function remaining(array $block, string $type, int $docId): ?float
    {
        $item = $this->item($block, $type, $docId);
        return $item === null ? null : (float) $item['remaining_czk'];
    }

    private function journalCount(): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM journal_entries WHERE supplier_id = {$this->supplierId}")->fetchColumn();
    }

    private function assertDeductionError(string $code, callable $call): void
    {
        try {
            $call();
            self::fail('Očekávaná chyba ' . $code);
        } catch (JournalSettlementException $e) {
            self::assertSame($code, $e->errorCode, $e->getMessage());
        }
    }

    /** @param array<string,string> $query */
    private function callActionQuery(JournalSettlementAction $action, string $method, string $role, array $query): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/accounting/saldo-settlements/advance-candidates')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withQueryParams($query);
        $resp = $action->{$method}($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }

    /** @param array<string,mixed> $body */
    private function forced(JournalSettlementAction $action, string $method, string $httpMethod, array $body): array
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest($httpMethod, '/api/accounting/saldo-settlements/advance-deductions')
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(AuthMiddleware::ATTR_METHOD, 'session')
            ->withQueryParams(['force' => '1'])
            ->withParsedBody($body);
        $resp = $action->{$method}($req, new Psr7Response());
        $resp->getBody()->rewind();
        $decoded = json_decode((string) $resp->getBody(), true);
        return ['status' => $resp->getStatusCode(), 'body' => is_array($decoded) ? $decoded : []];
    }
}
