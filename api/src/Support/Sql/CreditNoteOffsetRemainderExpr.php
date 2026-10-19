<?php

declare(strict_types=1);

namespace MyInvoice\Support\Sql;

/**
 * Složení části dokladu, kterou zbývá uhradit penězi, když ho zčásti vyrovnal zápočet
 * dobropisu (`credit_note_offsets`, {@see \MyInvoice\Service\Invoice\CreditNoteOffsetService}).
 *
 * Zápočet není peněžní tok, peněžní deník bere jen úhradu zbytku faktury. Zbytek ale nemá
 * složení celé faktury: dobropis z ní ubral část ve SVÉM složení (jiná sazba DPH, jen
 * některé položky nebo kusy). Zbytek je proto faktura minus započtené dobropisy, brutto
 * i DPH prostým odečtením (zápočet vzniká vždy v plné výši dobropisu). Dělit zbytek poměrem
 * celé faktury nadsadí nebo podhodnotí daňový příjem a výdaj: u faktury se sazbami 21 %
 * a 0 %, ze které dobropis stornuje položku 21 %, by zbytek bez DPH nesl DPH.
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
        $remaining = self::remainingSql($docType, $alias, 'total_with_vat');
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
        return '(CASE WHEN ' . self::remainingSql($docType, $alias, 'total_with_vat') . ' > 0.005
                      THEN ' . self::remainingSql($docType, $alias, 'total_vat') . " ELSE {$alias}.total_vat END)";
    }

    /**
     * Sloupec dokladu minus týž sloupec dobropisů započtených proti němu. Dobropis má brutto
     * i DPH záporné (kladně uložený dobropis z cizího systému kladné), odečítá se velikost.
     */
    private static function remainingSql(string $docType, string $alias, string $column): string
    {
        [$type, $table] = self::document($docType);
        return "({$alias}.{$column} - (SELECT COALESCE(SUM(ABS(cnr_d.{$column})), 0)
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
