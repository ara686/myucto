<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Složení části dokladu, kterou zbývá uhradit penězi, když ho zčásti vyrovnal zápočet
 * dobropisu (`credit_note_offsets`, {@see \MyInvoice\Service\Invoice\CreditNoteOffsetService}).
 *
 * Zápočet není peněžní tok, peněžní deník bere jen úhradu zbytku faktury. Zbytek ale nemá
 * složení celé faktury: dobropis z ní ubral část ve SVÉM složení (jiná sazba DPH, jen
 * některé položky nebo kusy). Zbytek je proto faktura minus započtené dobropisy. Dělit zbytek
 * poměrem celé faktury nadsadí nebo podhodnotí daňový příjem a výdaj: u faktury se sazbami
 * 21 % a 0 %, ze které dobropis stornuje položku 21 %, by zbytek bez DPH nesl DPH.
 *
 * Brutto zbytku odečítá částku zápočtu (`credit_note_offsets.amount`), tedy tutéž veličinu,
 * kterou od faktury odečítá noha C2 peněžního deníku i {@see PurchaseSettledExpr}. DPH zbytku
 * odečítá DPH dobropisu: kryje-li zápočet celé brutto dobropisu (běžný případ), celé DPH
 * dobropisu prostým odečtením, jinak (dobropis s odečtenou zálohou) jeho poměrnou část
 * podle částky zápočtu. Znaménko dobropisu určuje jeho brutto, ne každý sloupec zvlášť
 * (stejně jako {@see \MyInvoice\Service\Report\VatLedgerService::purchaseDocumentSignSql()}):
 * opravný dobropis s brutto −395 a DPH +105 (vrací položku 0 %, doúčtuje položku 21 %)
 * DPH zbytku zvýší.
 *
 * Bez zápočtu je to doklad sám. Doklad celý vyrovnaný zápočtem zbytek nemá, jeho případná
 * úhrada (přeplatek) se dělí podle dokladu. Dobropis se při zápočtu spotřebuje ve vlastním
 * složení, úhrada zbytku dobropisu se proto dělí podle dobropisu.
 *
 * Částky jsou v měně dokladu (v ní je i dobropis). Úhradu pak peněžní deník dělí na základ
 * a DPH poměrem DPH zbytku k brutto zbytku; u úhrady celého zbytku to dá přesně rozdíl
 * zbytku, poměr je nutný jen pro částečnou úhradu a úhradu v jiné měně.
 *
 * Přijatá strana odečítá týmž pravidlem daňovou část dokladů v
 * {@see \MyInvoice\Service\TaxEvidence\TaxExpenseAllocationCalculator} (řádkový rozpis,
 * krácený odpočet); tenhle výraz u ní slouží jen ručnímu zařazení pohybu s vazbou na doklad.
 */
final class CreditNoteOffsetRemainderExpr
{
    /**
     * Brutto části dokladu k úhradě penězi.
     *
     * @param string $docType `invoice` (vydaný doklad) nebo `purchase_invoice` (přijatý)
     * @param string $alias   alias tabulky dokladu v okolním dotazu
     */
    public static function grossSql(string $docType, string $alias): string
    {
        $remaining = self::remainingGrossSql($docType, $alias);
        return "(CASE WHEN {$remaining} > 0.005 THEN {$remaining} ELSE {$alias}.total_with_vat END)";
    }

    /**
     * DPH části dokladu k úhradě penězi.
     *
     * @param string $docType `invoice` (vydaný doklad) nebo `purchase_invoice` (přijatý)
     * @param string $alias   alias tabulky dokladu v okolním dotazu
     */
    public static function vatSql(string $docType, string $alias): string
    {
        return '(CASE WHEN ' . self::remainingGrossSql($docType, $alias) . ' > 0.005
                      THEN ' . self::remainingVatSql($docType, $alias) . " ELSE {$alias}.total_vat END)";
    }

    /** Brutto dokladu minus částky zápočtů dobropisů proti němu. */
    private static function remainingGrossSql(string $docType, string $alias): string
    {
        [$type] = self::document($docType);
        return "({$alias}.total_with_vat - (SELECT COALESCE(SUM(cnr_o.amount), 0)
                     FROM credit_note_offsets cnr_o
                    WHERE cnr_o.supplier_id = {$alias}.supplier_id AND cnr_o.doc_type = '{$type}'
                      AND cnr_o.invoice_id = {$alias}.id))";
    }

    /**
     * DPH dokladu minus DPH započtených dobropisů. `SIGN(brutto) × DPH` je DPH dobropisu ve
     * směru snížení faktury; poměrem se počítá jen zápočet, který se od brutto dobropisu liší.
     */
    private static function remainingVatSql(string $docType, string $alias): string
    {
        [$type, $table] = self::document($docType);
        return "({$alias}.total_vat - (SELECT COALESCE(SUM(
                         CASE WHEN cnr_o.amount = ABS(cnr_d.total_with_vat)
                              THEN SIGN(cnr_d.total_with_vat) * cnr_d.total_vat
                              ELSE cnr_o.amount * cnr_d.total_vat / NULLIF(cnr_d.total_with_vat, 0) END), 0)
                     FROM credit_note_offsets cnr_o
                     JOIN {$table} cnr_d ON cnr_d.id = cnr_o.credit_note_id AND cnr_d.supplier_id = cnr_o.supplier_id
                    WHERE cnr_o.supplier_id = {$alias}.supplier_id AND cnr_o.doc_type = '{$type}'
                      AND cnr_o.invoice_id = {$alias}.id))";
    }

    /** @return array{0:string,1:string} `credit_note_offsets.doc_type` a tabulka dokladů */
    private static function document(string $docType): array
    {
        return $docType === 'purchase_invoice' ? ['purchase_invoice', 'purchase_invoices'] : ['invoice', 'invoices'];
    }
}
