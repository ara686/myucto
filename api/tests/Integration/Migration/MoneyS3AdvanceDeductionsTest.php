<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Migration;

use MyInvoice\Service\Accounting\Reports\SaldoService;
use MyInvoice\Service\Migration\MoneyS3\ImportOptions;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Tests\Fixtures\MoneyS3\SyntheticAgenda;
use PDO;

/**
 * Čerpání poskytnutých záloh, které Money vede bez odkazu na zálohu
 * ({@see SyntheticAgenda::filesWithAdvanceDeductions()}): měsíční vyúčtování vlastním
 * symbolem faktury, roční vyúčtování víc záloh, vrácení na zálohu a odkaz na DDKP v popisu.
 * Řádný i opravný převod je musí spárovat tak, aby saldokonto 314 odpovídalo hlavní knize,
 * opakovaný běh nic nezmění a vazby zadané v MyÚčtu zůstanou.
 */
final class MoneyS3AdvanceDeductionsTest extends MoneyS3ImportTestCase
{
    private int $supplierId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        SyntheticAgenda::writeLzFiles($this->tmp . '/agenda.lz', SyntheticAgenda::filesWithAdvanceDeductions());
        $this->supplierId = $this->supplier();
        $protocol = $this->convert(false);
        self::assertFalse($protocol->hasErrors(), $this->explain($protocol));
    }

    public function testMonthlySettlementWithOwnSymbolConsumesPreviousAdvance(): void
    {
        // FP25101 čerpá zálohu z doby před převodem (počáteční stav), další měsíce vždy předchozí DDKP.
        self::assertSame([
            ['FP25102', 'DD25101', '1000.00'],
            ['FP25103', 'DD25102', '1000.00'],
        ], $this->deductions('33445567'));
        self::assertSame([], $this->openOn314('33445567'));
    }

    public function testAnnualSettlementRefundAndReferenceCloseAllAdvances(): void
    {
        self::assertSame([
            ['FP25111', 'DD25111', '1000.00'],
            ['FP25111', 'DD25112', '1000.00'],
            ['FP25111', 'DD25113', '500.00'],
            ['FP25113', 'DD25113', '500.00'],
            ['FP25113', 'FP25112', '300.00'],
        ], $this->deductions('44556675'));
        self::assertSame([], $this->openOn314('44556675'));
        // Hlavní kniha 314 nese mimo zálohy Delty a Epsilonu jen daň DZ25001 bez úhrady (-210),
        // kterou saldokonto jako položku nevede; zálohy samy jsou vyrovnané.
        $saldo = $this->saldo314();
        self::assertSame([], $saldo['partners'], json_encode($saldo));
        self::assertSame(-210.0, $saldo['difference'], json_encode($saldo));
    }

    public function testRelinkChangesNothingAndKeepsUserLinks(): void
    {
        $before = $this->state();
        $again = $this->convert(true);
        self::assertFalse($again->hasErrors(), $this->explain($again));
        self::assertSame($before, $this->state(), 'Opakovaný převod nesmí nic změnit.');

        $sid = $this->supplierId;
        $pdo = $this->db->pdo();
        // Uživatel čerpání FP25103 smazal a FP25102 svázal se zálohou DD25102 po svém.
        $pdo->prepare('DELETE FROM purchase_advance_deductions WHERE supplier_id = ? AND final_invoice_id = ?')->execute([$sid, $this->doc('FP25103')]);
        $pdo->prepare('DELETE FROM purchase_advance_deductions WHERE supplier_id = ? AND final_invoice_id = ?')->execute([$sid, $this->doc('FP25102')]);
        $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ?')->execute([$this->doc('DD25102'), $this->doc('FP25102')]);
        $repair = $this->convert(true);
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame([], $this->deductions('33445567'), 'Smazané čerpání ani vazbu uživatele převod nepřepíše.');
        self::assertSame($this->doc('DD25102'), (int) $pdo->query('SELECT advance_purchase_invoice_id FROM purchase_invoices WHERE id = ' . $this->doc('FP25102'))->fetchColumn());
    }

    public function testJournalStaysUntouched(): void
    {
        $sid = $this->supplierId;
        $journal = fn (): array => $this->db->pdo()->query(
            "SELECT e.id, e.entry_date, e.source_type, e.source_id, l.account_id, l.side, l.amount
               FROM journal_entries e JOIN journal_entry_lines l ON l.entry_id = e.id
              WHERE e.supplier_id = {$sid} ORDER BY e.id, l.id"
        )->fetchAll(PDO::FETCH_NUM);
        $before = $journal();
        $this->db->pdo()->prepare('DELETE FROM purchase_advance_deductions WHERE supplier_id = ?')->execute([$sid]);
        $this->db->pdo()->prepare("DELETE FROM money_s3_import_map WHERE supplier_id = ? AND kind = 'advance_deduction'")->execute([$sid]);
        $repair = $this->convert(true);
        self::assertFalse($repair->hasErrors(), $this->explain($repair));
        self::assertSame($before, $journal());
        self::assertCount(2, $this->deductions('33445567'), 'Opravný převod doplní čerpání firmě převedené dřívější verzí.');
    }

    private function convert(bool $relink): ImportProtocol
    {
        return $this->importer->run($this->supplierId, $this->userId, $this->backup(),
            new ImportOptions(ImportOptions::MODE_IMPORT, true, null, [], [], false, 2025, ImportOptions::DISPOSAL_YEAR_TAX_HALF, false, $relink));
    }

    /** @return list<array{0:string,1:string,2:string}> konečná faktura, záloha, částka (čísla dokladů Money) */
    private function deductions(string $ico): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT f.varsymbol, a.varsymbol, pad.amount FROM purchase_advance_deductions pad
               JOIN purchase_invoices f ON f.id = pad.final_invoice_id
               JOIN purchase_invoices a ON a.id = pad.advance_invoice_id
               JOIN clients c ON c.id = f.vendor_id
              WHERE pad.supplier_id = ? AND c.ic = ? ORDER BY f.varsymbol, a.varsymbol'
        );
        $stmt->execute([$this->supplierId, $ico]);
        return $stmt->fetchAll(PDO::FETCH_NUM);
    }

    /** @return array<string,mixed> */
    private function saldo314(): array
    {
        $saldo = $this->container->get(SaldoService::class);
        $account = $saldo->build($this->supplierId, $this->periodId($this->supplierId, 2025), '2025-12-31', '314')['accounts'][0];
        return ['difference' => round((float) $account['difference'], 2), 'gl' => $account['gl_balance'], 'partners' => $account['partners']];
    }

    /** @return list<string> */
    private function openOn314(string $ico): array
    {
        $open = [];
        foreach ($this->saldo314()['partners'] as $partner) {
            foreach ($partner['items'] as $item) {
                $vendorIco = $this->db->pdo()->query('SELECT c.ic FROM purchase_invoices p JOIN clients c ON c.id = p.vendor_id WHERE p.id = ' . (int) $item['doc_id'])->fetchColumn();
                if ($vendorIco === $ico) {
                    $open[] = $item['doc_no'] . ' ' . $item['remaining_czk'];
                }
            }
        }
        return $open;
    }

    private function doc(string $no): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM purchase_invoices WHERE supplier_id = ? AND varsymbol = ?');
        $stmt->execute([$this->supplierId, $no]);
        $id = (int) $stmt->fetchColumn();
        self::assertGreaterThan(0, $id, "{$no} nenalezen");
        return $id;
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $out = [];
        foreach ([
            'pad' => 'SELECT id, final_invoice_id, advance_invoice_id, amount FROM purchase_advance_deductions WHERE supplier_id = ? ORDER BY id',
            'links' => 'SELECT id, advance_purchase_invoice_id, parent_purchase_invoice_id FROM purchase_invoices WHERE supplier_id = ? ORDER BY id',
            'payment_matches' => 'SELECT id, bank_transaction_id, purchase_invoice_id, amount FROM payment_matches WHERE supplier_id = ? ORDER BY id',
        ] as $key => $sql) {
            $stmt = $this->db->pdo()->prepare($sql);
            $stmt->execute([$this->supplierId]);
            $out[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        return $out;
    }
}
