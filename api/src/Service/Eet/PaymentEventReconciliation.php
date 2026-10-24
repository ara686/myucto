<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Napojení bankovního důkazu na již zachycenou platební událost (hook pro
 * StatementMatcher). Doplní jen stav bankovního ověření a vazbu na pohyb;
 * nevytváří druhou tržbu ani druhou platební událost.
 *
 * Účetní úhradu faktury dál zakládá bankovní párování svou běžnou cestou
 * (`InvoicePaymentService::recordPayment(source = bank)`); její id se sem předá,
 * aby událost přešla do `accounting_settlement_status = settled`. Alokace s bankovním
 * pohybem není fiskálním zachycením platby, takže ochrana proti smazání úhrady
 * s evidencí EET se na ni nevztahuje (zrušení spárování zůstává možné).
 */
final class PaymentEventReconciliation
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Volat v transakci párování. Vrací false, když událost neexistuje nebo nejde
     * o čekající bezhotovostní platbu (hotovost se bankou neověřuje).
     */
    public function attachBankTransaction(int $supplierId, int $paymentEventId, int $bankTransactionId, string $amount, ?int $invoicePaymentId = null): bool
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            "UPDATE payment_events
                SET bank_verification_status = 'verified',
                    payment_order_status = IF(payment_order_status = 'operator_confirmed', 'settled', payment_order_status),
                    accounting_settlement_status = IF(? IS NULL, accounting_settlement_status, 'settled')
              WHERE id = ? AND supplier_id = ? AND bank_verification_status = 'pending'
                AND payment_order_status IN ('operator_confirmed', 'settled')"
        );
        $stmt->execute([$invoicePaymentId, $paymentEventId, $supplierId]);
        if ($stmt->rowCount() !== 1) {
            return false;
        }
        $invoice = $pdo->prepare('SELECT invoice_id FROM payment_events WHERE id = ? AND supplier_id = ?');
        $invoice->execute([$paymentEventId, $supplierId]);
        $invoiceId = $invoice->fetchColumn();
        $pdo->prepare(
            'INSERT INTO payment_event_allocations
               (supplier_id, payment_event_id, invoice_id, invoice_payment_id, bank_transaction_id, amount)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $supplierId, $paymentEventId,
            $invoiceId !== false && $invoiceId !== null ? (int) $invoiceId : null,
            $invoicePaymentId, $bankTransactionId, EetValues::amount($amount),
        ]);
        return true;
    }
}
