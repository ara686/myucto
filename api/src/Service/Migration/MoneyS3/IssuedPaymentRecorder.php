<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Evidovaná platba vydané faktury (`invoice_payments`) k úhradě z banky či pokladny
 * převedené z Money. Z ní čte uhrazenou část vydané faktury saldokonto, kontrola
 * úplnosti i uvolnění pohybu; samotné párování (`payment_matches`, vazba pokladního
 * dokladu) ji nenahradí.
 *
 * Stav faktury a uhrazenou částku vede převod podle Money (`Uhrazeno`), platby ji jen
 * doplňují: jejich součet nepřesáhne `paid_total`. Částka je v měně faktury. Platby se
 * zakládají běžným a zálohovým fakturám (úhrada proformy je inkasem zálohy na 324, čerpá
 * ji konečná faktura navázaná {@see AdvanceLinker}); dobropis ani daňový doklad k platbě
 * se v MyÚčtu neplatí. Opakovaný převod platbu nezdvojí (banka: pohyb + faktura, pokladna
 * a zápočet dobropisu: poznámka s číslem dokladu + faktura).
 */
final class IssuedPaymentRecorder
{
    /** @var array<int,array{status:string,type:string,paid_total:float,rate:?float,currency:string,recorded:float}> */
    private array $invoices = [];

    public function __construct(private readonly Connection $db) {}

    /**
     * Měna a kurz faktury pro přepočet úhrady; null = faktura platbu nedostane.
     *
     * @return array{currency:string,rate:?float}|null
     */
    public function invoiceCurrency(int $supplierId, int $invoiceId): ?array
    {
        $row = $this->invoice($supplierId, $invoiceId);
        return $row === null ? null : ['currency' => $row['currency'], 'rate' => $row['rate']];
    }

    /**
     * Částka úhrady v měně faktury: shodná měna platby se bere přímo, jinak se koruny
     * přepočtou kurzem dokladu. Null, když přepočet nejde (cizí měna bez kurzu).
     */
    public function toInvoiceCurrency(int $supplierId, int $invoiceId, float $czk, ?string $movementCurrency = null, ?float $movementAmount = null): ?float
    {
        $currency = $this->invoiceCurrency($supplierId, $invoiceId);
        if ($currency === null) {
            return null;
        }
        if ($movementCurrency !== null && $movementAmount !== null && $movementCurrency === $currency['currency']) {
            return abs($movementAmount);
        }
        if ($currency['currency'] === 'CZK') {
            return abs($czk);
        }
        return $currency['rate'] !== null ? abs($czk) / $currency['rate'] : null;
    }

    /**
     * @param 'bank'|'cash'|'credit_note'|'settlement' $kind
     * @return int|null id založené platby
     */
    public function record(ImportContext $ctx, int $invoiceId, string $kind, ?int $txId, ?int $cashId, float $amount, string $date, string $paymentNo): ?int
    {
        $row = $this->invoice($ctx->supplierId, $invoiceId);
        if ($row === null) {
            return null;
        }
        $pdo = $this->db->pdo();
        $note = match ($kind) {
            'credit_note' => 'Převzato z Money S3, zápočet dobropisu ',
            'settlement' => 'Převzato z Money S3, zápočet interním dokladem ',
            default => 'Převzato z Money S3, úhrada ',
        } . $paymentNo;
        if ($kind === 'bank') {
            $dup = $pdo->prepare('SELECT 1 FROM invoice_payments WHERE bank_transaction_id = ? AND invoice_id = ?');
            $dup->execute([$txId, $invoiceId]);
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM invoice_payments WHERE invoice_id = ? AND source = ? AND note = ?');
            $dup->execute([$invoiceId, $kind, $note]);
        }
        if ($dup->fetchColumn() !== false) {
            return null;
        }
        $amount = round(min(abs($amount), $row['paid_total'] - $row['recorded']), 2);
        if ($amount < 0.01) {
            return null;
        }
        $pdo->prepare(
            'INSERT INTO invoice_payments (supplier_id, invoice_id, paid_on, amount, currency, note, source, bank_transaction_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ctx->supplierId, $invoiceId, substr($date, 0, 10), number_format($amount, 2, '.', ''), $row['currency'],
            $note, $kind, $kind === 'bank' ? $txId : null, $ctx->userId > 0 ? $ctx->userId : null,
        ]);
        $paymentId = (int) $pdo->lastInsertId();
        if ($kind === 'cash') {
            $pdo->prepare('UPDATE cash_documents SET invoice_payment_id = ? WHERE id = ? AND supplier_id = ? AND invoice_id = ? AND invoice_payment_id IS NULL')
                ->execute([$paymentId, $cashId, $ctx->supplierId, $invoiceId]);
        }
        $this->invoices[$invoiceId]['recorded'] = $row['recorded'] + $amount;
        $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'issued_payments_recorded');
        return $paymentId;
    }

    /** @return array{status:string,type:string,paid_total:float,rate:?float,currency:string,recorded:float}|null */
    private function invoice(int $supplierId, int $invoiceId): ?array
    {
        if (!array_key_exists($invoiceId, $this->invoices)) {
            $stmt = $this->db->pdo()->prepare(
                "SELECT i.status, i.invoice_type, i.paid_total, i.exchange_rate, cur.code AS currency,
                        (SELECT COALESCE(SUM(ip.amount), 0) FROM invoice_payments ip WHERE ip.invoice_id = i.id) AS recorded
                   FROM invoices i JOIN currencies cur ON cur.id = i.currency_id
                  WHERE i.id = ? AND i.supplier_id = ?"
            );
            $stmt->execute([$invoiceId, $supplierId]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->invoices[$invoiceId] = $r === false ? null : [
                'status' => (string) $r['status'],
                'type' => (string) $r['invoice_type'],
                'paid_total' => (float) $r['paid_total'],
                'rate' => $r['exchange_rate'] !== null && (float) $r['exchange_rate'] > 0 ? (float) $r['exchange_rate'] : null,
                'currency' => (string) $r['currency'],
                'recorded' => (float) $r['recorded'],
            ];
        }
        $row = $this->invoices[$invoiceId];
        if ($row === null || !in_array($row['type'], ['invoice', 'proforma'], true) || in_array($row['status'], ['draft', 'cancelled'], true)) {
            return null;
        }
        return $row;
    }
}
