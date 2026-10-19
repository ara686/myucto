<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\TaxEvidence;

use MyInvoice\Service\Invoice\CreditNoteOffsetService;
use MyInvoice\Service\Report\VatLedgerService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Úhrada zbytku faktury po zápočtu dobropisu u plátce DPH: daňová část a DPH úhrady
 * musí odpovídat faktuře minus dobropis, ne poměru DPH celé faktury.
 *
 * Zápočet dobropisu není peněžní tok (migrace 1988): do peněžního deníku vstupuje jen
 * skutečně zaplacený zbytek faktury. Má-li faktura položky s různými sazbami DPH a dobropis
 * vrací jen některé (celé nebo část kusů), zbytek nemá složení celé faktury. Rozdělení
 * zbytku poměrem DPH celé faktury pak nadsadí nebo podhodnotí daňový příjem a výdaj.
 * U faktury s jednou sazbou má dobropis týž poměr a rozdělení sedí.
 *
 * Peněžní deník sazby nerozlišuje (zná jen daňovou část a DPH). Po sazbách se proto
 * kontroluje evidence DPH, ze které se skládá přiznání i kontrolní hlášení: dobropis smí
 * snížit jen sazby svých položek, ostatní sazby zůstanou přesně jako na faktuře, a zápočet
 * ani úhrada na evidenci DPH nic nemění.
 *
 * Stejné rozdělení žije na každé cestě úhrady do peněžního deníku, proto se ověřují
 * všechny: banka, pokladna a ruční úhrada, na vydané i přijaté straně.
 */
#[Group('integration')]
final class CreditNoteOffsetVatSplitTest extends CashJournalTestCase
{
    private CreditNoteOffsetService $offsets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->offsets = $this->container->get(CreditNoteOffsetService::class);
    }

    /**
     * Položky faktury a dobropisu [sazba %, počet kusů, cena za kus bez DPH] (dobropis vrací
     * kusy záporně), očekávaná evidence DPH faktury a dobropisu dohromady po sazbách
     * [sazba => [základ, DPH]] a rozdělení zaplaceného zbytku [daňová část, DPH].
     *
     * @return array<string,array{invoice:list<array{0:int,1:float,2:float}>,credit_note:list<array{0:int,1:float,2:float}>,by_rate:array<int,array{0:float,1:float}>,payment:array{0:float,1:float}}>
     */
    private static function shapes(): array
    {
        return [
            // Faktura: služba 21 % 1 000 + 210 a položka 0 % 1 000 = 2 210. Dobropis stornuje
            // celou službu. Zaplacený zbytek 1 000 je jen položka bez DPH.
            'sazby 21 % a 0 %, dobropis stornuje položku 21 %' => [
                'invoice' => [[21, 1.0, 1000.0], [0, 1.0, 1000.0]],
                'credit_note' => [[21, -1.0, 1000.0]],
                'by_rate' => [0 => [1000.0, 0.0], 21 => [0.0, 0.0]],
                'payment' => [1000.0, 0.0],
            ],
            // Faktura: zboží 21 % 10 ks × 100 = 1 000 + 210 a položka 0 % 1 000 = 2 210.
            // Dobropis vrací 4 ks zboží: −400 − 84. Zaplacený zbytek 1 726 = 600 + 1 000 + DPH 126.
            'sazby 21 % a 0 %, dobropis vrací 4 z 10 ks zboží 21 %' => [
                'invoice' => [[21, 10.0, 100.0], [0, 1.0, 1000.0]],
                'credit_note' => [[21, -4.0, 100.0]],
                'by_rate' => [0 => [1000.0, 0.0], 21 => [600.0, 126.0]],
                'payment' => [1600.0, 126.0],
            ],
            // Faktura: dvě položky 12 % (500 + 60 a 3 ks × 100 = 300 + 36) a položka 21 %
            // 1 000 + 210 = 2 106. Dobropis stornuje druhou položku 12 %: −300 − 36. Sazba 21 %
            // zůstane nedotčená. Zaplacený zbytek 1 770 = 500 + 1 000 + DPH 60 + 210.
            'dvě položky 12 % a položka 21 %, dobropis stornuje jednu položku 12 %' => [
                'invoice' => [[12, 1.0, 500.0], [12, 3.0, 100.0], [21, 1.0, 1000.0]],
                'credit_note' => [[12, -3.0, 100.0]],
                'by_rate' => [12 => [500.0, 60.0], 21 => [1000.0, 210.0]],
                'payment' => [1500.0, 270.0],
            ],
            // Kontrola: faktura jen se sazbou 21 %, 10 ks × 100 = 1 000 + 210. Dobropis vrací
            // 4 ks: −400 − 84, týž poměr DPH. Zaplacený zbytek 726 = 600 + DPH 126.
            'jen sazba 21 %, dobropis vrací 4 z 10 ks' => [
                'invoice' => [[21, 10.0, 100.0]],
                'credit_note' => [[21, -4.0, 100.0]],
                'by_rate' => [21 => [600.0, 126.0]],
                'payment' => [600.0, 126.0],
            ],
        ];
    }

    /** @return iterable<string,array{0:string,1:string,2:array<string,mixed>}> */
    public static function cases(): iterable
    {
        foreach (self::shapes() as $shape => $documents) {
            foreach (['issued' => 'vydaná', 'purchase' => 'přijatá'] as $side => $sideLabel) {
                foreach (['bank' => 'banka', 'cash' => 'pokladna', 'manual' => 'ruční úhrada'] as $instrument => $instrumentLabel) {
                    yield "{$sideLabel}, {$instrumentLabel}, {$shape}" => [$side, $instrument, $documents];
                }
            }
        }
    }

    /**
     * @param array{invoice:list<array{0:int,1:float,2:float}>,credit_note:list<array{0:int,1:float,2:float}>,by_rate:array<int,array{0:float,1:float}>,payment:array{0:float,1:float}} $shape
     */
    #[DataProvider('cases')]
    public function testRemainderAfterCreditNoteOffsetIsSplitAsInvoiceMinusCreditNote(string $side, string $instrument, array $shape): void
    {
        $issued = $side === 'issued';
        $invoiceId = $this->document($issued, $shape['invoice'], false);
        $creditNoteId = $this->document($issued, $shape['credit_note'], true);
        $this->db->pdo()->prepare($issued
            ? 'UPDATE invoices SET parent_invoice_id = ? WHERE id = ?'
            : 'UPDATE purchase_invoices SET parent_purchase_invoice_id = ? WHERE id = ?')->execute([$invoiceId, $creditNoteId]);

        // Evidence DPH po sazbách: faktura minus dobropis, sazby mimo dobropis jako na faktuře.
        $vatByRate = $this->vatByRate($issued, [$invoiceId, $creditNoteId]);
        self::assertSame($shape['by_rate'], $vatByRate, 'evidence DPH po sazbách [základ, DPH] = faktura minus dobropis');
        $invoiceOnly = $this->vatByRate($issued, [$invoiceId]);
        $creditNoteRates = array_map(static fn (array $item): int => $item[0], $shape['credit_note']);
        foreach (array_diff(array_keys($invoiceOnly), $creditNoteRates) as $rate) {
            self::assertSame($invoiceOnly[$rate], $vatByRate[$rate], "sazba {$rate} %: dobropis na ni nesahá, zůstane jako na faktuře");
        }

        $result = $issued
            ? $this->offsets->applyForInvoice($this->supplierId, $creditNoteId, $this->userId)
            : $this->offsets->applyForPurchase($this->supplierId, $creditNoteId, $this->userId);
        self::assertNotNull($result['offset_id'], (string) $result['reason']);

        [$taxable, $vat] = $shape['payment'];
        $this->pay($issued, $instrument, $invoiceId, round($taxable + $vat, 2));

        $res = $this->fullYear($this->supplierId, true);
        $bucket = $issued ? 'prijem' : 'vydaj';
        self::assertSame(1, $this->countRows($res), 'v peněžním deníku je jen skutečná úhrada zbytku, zápočet ne');
        self::assertEqualsWithDelta($taxable, $res['totals'][$bucket . '_danovy'], 0.005,
            'daňová část úhrady = základ faktury minus základ dobropisu');
        self::assertEqualsWithDelta($vat, $res['totals'][$bucket . '_nedanovy'], 0.005,
            'DPH úhrady = DPH faktury minus DPH dobropisu');
        self::assertEqualsWithDelta(0.0, $res['totals']['nezarazeno'], 0.005);

        self::assertSame($vatByRate, $this->vatByRate($issued, [$invoiceId, $creditNoteId]),
            'zápočet ani úhrada evidenci DPH po sazbách nemění');
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * Faktura nebo dobropis s položkami; součty hlavičky jsou součty položek.
     *
     * @param list<array{0:int,1:float,2:float}> $items [sazba %, počet kusů, cena za kus bez DPH]
     */
    private function document(bool $issued, array $items, bool $creditNote): int
    {
        $lines = array_map(static function (array $item): array {
            [$rate, $quantity, $unitPrice] = $item;
            $base = round($quantity * $unitPrice, 2);
            return ['rate' => $rate, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'base' => $base, 'vat' => round($base * $rate / 100, 2)];
        }, $items);
        $base = round(array_sum(array_column($lines, 'base')), 2);
        $vat = round(array_sum(array_column($lines, 'vat')), 2);
        $opts = ['without' => $base, 'with' => round($base + $vat, 2), 'issue_date' => self::YEAR . ($creditNote ? '-06-05' : '-06-01')];
        $id = $issued
            ? $this->saleInvoice($this->supplierId, $opts + ['type' => $creditNote ? 'credit_note' : 'invoice'])
            : $this->purchaseInvoice($this->supplierId, $opts + ['document_kind' => $creditNote ? 'credit_note' : 'invoice']);

        $stmt = $this->db->pdo()->prepare(sprintf(
            'INSERT INTO %s
                (%s, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, ?, "ks", ?, ?, ?, ?, ?, ?, ?)',
            $issued ? 'invoice_items' : 'purchase_invoice_items',
            $issued ? 'invoice_id' : 'purchase_invoice_id',
        ));
        foreach ($lines as $index => $line) {
            $stmt->execute([$id, "Položka {$line['rate']} %", $line['quantity'], $line['unit_price'], $this->vatRateId($line['rate']),
                $line['rate'], $line['base'], $line['vat'], round($line['base'] + $line['vat'], 2), $index]);
        }
        return $id;
    }

    /** Česká sazba DPH z číselníku (položka nese sazbu i ve snapshotu). */
    private function vatRateId(int $rate): int
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id FROM vat_rates WHERE country = 'CZ' ORDER BY rate_percent = ? DESC, valid_to IS NULL DESC, id LIMIT 1"
        );
        $stmt->execute([$rate]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Evidence DPH dokladů za rok po sazbách [sazba => [základ, DPH]] v Kč se znaménkem
     * dokladu (dobropis záporně) — řádky, ze kterých se skládá přiznání i kontrolní hlášení.
     *
     * @param list<int> $documentIds
     * @return array<int,array{0:float,1:float}>
     */
    private function vatByRate(bool $issued, array $documentIds): array
    {
        $out = [];
        foreach ($this->container->get(VatLedgerService::class)->rows($this->supplierId, self::YEAR . '-01-01', self::YEAR . '-12-31') as $row) {
            if ($row['source'] !== ($issued ? 'sale' : 'purchase') || !in_array($row['invoice_id'], $documentIds, true)) {
                continue;
            }
            $rate = (int) round((float) $row['vat_rate']);
            $out[$rate] = [round(($out[$rate][0] ?? 0.0) + (float) $row['base_czk'], 2), round(($out[$rate][1] ?? 0.0) + (float) $row['vat_czk'], 2)];
        }
        ksort($out);
        return $out;
    }

    /** Úhrada zbytku faktury zvolenou cestou do peněžního deníku. */
    private function pay(bool $issued, string $instrument, int $invoiceId, float $amount): void
    {
        $date = self::YEAR . '-06-15';
        if ($issued) {
            if ($instrument === 'bank') {
                $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), $amount);
                $this->invoicePayment($this->supplierId, $invoiceId, $amount, 'bank', $tx, $date);
            } elseif ($instrument === 'cash') {
                // Pokladní úhrada zapíše pokladní doklad i platbu faktury (source = cash).
                $this->cashDoc('in', 'invoice_payment', $amount, ['invoice_id' => $invoiceId], null, $date);
                $this->invoicePayment($this->supplierId, $invoiceId, $amount, 'cash', null, $date);
            } else {
                $this->invoicePayment($this->supplierId, $invoiceId, $amount, 'manual', null, $date);
            }
            $this->db->pdo()->prepare("UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?")->execute([$date, $invoiceId]);
            return;
        }
        if ($instrument === 'bank') {
            $tx = $this->bankTx($this->statement($this->supplierId, $this->accountA), -$amount);
            $this->paymentMatch($this->supplierId, $tx, $invoiceId, $amount);
        } elseif ($instrument === 'cash') {
            $this->cashDoc('out', 'purchase_payment', $amount, ['purchase_invoice_id' => $invoiceId], null, $date);
        }
        // Ruční úhrada přijaté faktury je jen stav „zaplaceno" s datem úhrady (virtuální noha).
        $this->db->pdo()->prepare("UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?")->execute([$date, $invoiceId]);
    }
}
