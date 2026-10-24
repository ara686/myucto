<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\Report\VatClassificationDefaulter;

/**
 * Výchozí klasifikace DPH zakládaného vydaného dokladu (řádky i hlavička), pokud ji
 * volající nezadal. Společná pro založení faktury v editoru
 * ({@see \MyInvoice\Action\Invoice\CreateInvoiceAction}) i pro rychlou účtenku
 * ({@see \MyInvoice\Service\Mobile\QuickReceiptService}), ať obě cesty zařadí stejné
 * plnění na stejný řádek přiznání.
 */
final class NewInvoiceVatClassification
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly VatClassificationDefaulter $vatDefaulter,
    ) {}

    /** @param array<string,mixed> $body payload zakládaného dokladu (mění se) */
    public function apply(array &$body, int $supplierId): void
    {
        $vatRates = $this->repo->vatRateMap();
        $reverseCharge = !empty($body['reverse_charge']);
        // Country-aware RC: tuzemský odběratel → §92a (ř.25), zahraniční EU → dodání do JČS (ř.20).
        $customerEuForeign = $reverseCharge
            && (int) ($body['client_id'] ?? 0) > 0
            && $this->repo->clientIsEuForeign((int) $body['client_id']);

        if (!empty($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as &$item) {
                if (!empty($item['vat_classification_code']) || InvoiceItemKind::isText($item)) continue;
                $rateId = (int) ($item['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $taxDate = $body['tax_date'] ?? $body['issue_date'] ?? null;
                // Měrná jednotka řádku je signál zboží/služba pro RC prodej do EU (ř.20 vs ř.21).
                $units = ((string) ($item['unit'] ?? '') !== '') ? [(string) $item['unit']] : [];
                $item['vat_classification_code'] = $this->vatDefaulter->defaultForSale($rate, $reverseCharge, $taxDate, $supplierId, $customerEuForeign, $units);
            }
            unset($item);
        }

        if (empty($body['vat_classification_code']) && !empty($body['items'])) {
            $itemsWithTotals = array_map(function ($it) use ($vatRates) {
                $rateId = (int) ($it['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $qty = (float) ($it['quantity'] ?? 1);
                $price = (float) ($it['unit_price_without_vat'] ?? 0);
                return ['vat_rate' => $rate, 'total_with_vat' => $qty * $price * (1 + $rate / 100), 'unit' => (string) ($it['unit'] ?? '')];
            }, InvoiceItemKind::amountLines((array) $body['items']));
            $body['vat_classification_code'] = $this->vatDefaulter->suggestHeaderForInvoice(
                $itemsWithTotals,
                (bool) ($body['reverse_charge'] ?? false),
                'sale',
                $body['tax_date'] ?? $body['issue_date'] ?? null,
                $supplierId,
                $customerEuForeign,
            );
        }
    }
}
