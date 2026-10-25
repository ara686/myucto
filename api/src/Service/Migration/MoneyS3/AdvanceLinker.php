<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\MoneyS3;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Čerpání záloh z Money: vazby konečné faktury na zálohovou fakturu a daňového dokladu
 * k přijaté platbě (DDKP) na zálohu. Bez nich saldokonto 314/324 nepozná, že je záloha
 * vyúčtovaná, a drží ji otevřenou v plné výši.
 *
 * Money vede odpočet zálohy jako položku konečné faktury (`VFaktPol` / `PFaktPol`,
 * `Zaloha = 1`) s odkazem na zálohovou fakturu: `CisloZaFak` je interní číslo záznamu
 * zálohy, `RokZaFak` adresář roku (prázdný = tentýž rok). Odkaz se přijme jen na zálohovou
 * fakturu (druh L/Z/F), jejíž číslo položka v textu uvádí („Odpočet zálohy ZF190001"),
 * jinak Money odkazuje i na bankovní doklad úhrady. Čerpaná částka je cena položky
 * (u plátce základ, DPH čerpá DDKP).
 *
 * Přijatá faktura, která odečítá právě jednu zálohu čerpanou jen jí, dostane vazbu na
 * dokladu (`advance_purchase_invoice_id`); ostatní čerpání přijatých záloh jde do
 * `purchase_advance_deductions`. Vydaná faktura s jedinou zálohou dostane rodiče
 * (`parent_invoice_id`), víc záloh zůstane v protokolu k ruční kontrole.
 *
 * DDKP Money s zálohou svazuje jen textem, který samo generuje: položka odpočtu
 * („… (daň. dokl. DPP001)") a popis DDKP („Doklad k přijaté platbě (záloha ZF190001)").
 * Vazba se přijme jen k záloze téhož partnera.
 *
 * Existující vazby převod nepřepisuje a opakovaný převod nic nezdvojí.
 */
final class AdvanceLinker
{
    private const ADVANCE_KINDS = ['L', 'Z', 'F'];

    public function __construct(private readonly Connection $db) {}

    public function link(ImportContext $ctx): void
    {
        $dirs = [];
        foreach ($ctx->dirYears as $dir => $year) {
            if (isset($ctx->periods[$year])) {
                $dirs[] = $dir;
            }
        }
        if ($dirs === []) {
            return;
        }
        foreach ([
            ['VFaktury', 'VFaktPol', $ctx->issuedInvoices, true],
            ['PFaktury', 'PFaktPol', $ctx->purchaseInvoices, false],
        ] as [$headTable, $itemTable, $docs, $issued]) {
            $heads = [];
            foreach ($ctx->backup->rowsAcrossYears($headTable, ['Cislo', 'Doklad', 'Druh', 'Popis'], $dirs) as $r) {
                $year = $ctx->yearOf($r);
                $docNo = trim((string) ($r['Doklad'] ?? ''));
                $id = $docs[$year . '|' . $docNo] ?? null;
                if ($id === null) {
                    continue;
                }
                $heads[$r['__dir'] . '|' . (int) ($r['Cislo'] ?? 0)] = [
                    'id' => (int) $id, 'year' => (int) $year, 'doc' => $docNo,
                    'kind' => strtoupper(trim((string) ($r['Druh'] ?? ''))), 'text' => (string) ($r['Popis'] ?? ''),
                ];
            }
            $deductions = [];
            $taxDocuments = [];
            foreach ($ctx->backup->rowsAcrossYears($itemTable, ['Cislo', 'Popis', 'Cena', 'PocetMJ', 'Zaloha', 'CisloZaFak', 'RokZaFak'], $dirs) as $r) {
                if ((int) ($r['Zaloha'] ?? 0) !== 1 || (int) ($r['CisloZaFak'] ?? 0) <= 0) {
                    continue;
                }
                $final = $heads[$r['__dir'] . '|' . (int) ($r['Cislo'] ?? 0)] ?? null;
                $rok = trim((string) ($r['RokZaFak'] ?? ''));
                $advance = $heads[($rok !== '' ? 'ROK.' . str_pad($rok, 3, '0', STR_PAD_LEFT) : $r['__dir']) . '|' . (int) $r['CisloZaFak']] ?? null;
                $text = (string) ($r['Popis'] ?? '');
                if ($final === null || $advance === null || !in_array($advance['kind'], self::ADVANCE_KINDS, true)
                    || in_array($final['kind'], self::ADVANCE_KINDS, true) || !str_contains($text, $advance['doc'])) {
                    continue;
                }
                $amount = round(abs((float) ($r['Cena'] ?? 0) * (float) (($r['PocetMJ'] ?? 0) ?: 1)), 2);
                $deductions[$final['id']][$advance['id']] = round(($deductions[$final['id']][$advance['id']] ?? 0.0) + $amount, 2);
                if (preg_match('/da[nň]\.?\s*dokl\.?\s*([^\s)]+)/u', $text, $m) === 1) {
                    $taxDocuments[$final['year'] . '|' . $m[1]][$advance['id']] = true;
                }
            }
            // Popis DDKP („Doklad k přijaté platbě (záloha ZF190001)") - záloha z téhož nebo minulého roku.
            $advanceByNo = [];
            foreach ($heads as $h) {
                if (in_array($h['kind'], self::ADVANCE_KINDS, true)) {
                    $advanceByNo[$h['year'] . '|' . $h['doc']] = $h['id'];
                }
            }
            $ddkp = [];
            foreach ($heads as $h) {
                if ($h['kind'] !== 'D') {
                    continue;
                }
                foreach ($taxDocuments[$h['year'] . '|' . $h['doc']] ?? [] as $advanceId => $_) {
                    $ddkp[$h['id']][$advanceId] = true;
                }
                if (preg_match('/\(z[aá]loha\s+([^\s)]+)\)/u', $h['text'], $m) === 1) {
                    $advanceId = $advanceByNo[$h['year'] . '|' . $m[1]] ?? $advanceByNo[($h['year'] - 1) . '|' . $m[1]] ?? null;
                    if ($advanceId !== null) {
                        $ddkp[$h['id']][$advanceId] = true;
                    }
                }
            }
            $issued ? $this->linkIssued($ctx, $deductions, $ddkp) : $this->linkPurchase($ctx, $deductions, $ddkp);
        }
    }

    /**
     * @param array<int,array<int,float>> $deductions konečná faktura => záloha => čerpáno
     * @param array<int,array<int,true>> $ddkp DDKP => zálohy
     */
    private function linkPurchase(ImportContext $ctx, array $deductions, array $ddkp): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $finalsOf = [];
        foreach ($deductions as $finalId => $advances) {
            foreach ($advances as $advanceId => $_) {
                $finalsOf[$advanceId][$finalId] = true;
            }
        }
        $current = $pdo->prepare("SELECT advance_purchase_invoice_id FROM purchase_invoices WHERE id = ? AND supplier_id = ? AND document_kind = 'invoice'");
        $taken = $pdo->prepare('SELECT 1 FROM purchase_invoices WHERE supplier_id = ? AND advance_purchase_invoice_id = ? LIMIT 1');
        $set = $pdo->prepare('UPDATE purchase_invoices SET advance_purchase_invoice_id = ? WHERE id = ? AND supplier_id = ? AND advance_purchase_invoice_id IS NULL');
        $deduct = $pdo->prepare('INSERT IGNORE INTO purchase_advance_deductions (supplier_id, final_invoice_id, advance_invoice_id, amount, created_by) VALUES (?, ?, ?, ?, ?)');
        $userId = $ctx->userId > 0 ? $ctx->userId : null;
        foreach ($deductions as $finalId => $advances) {
            $current->execute([$finalId, $ctx->supplierId]);
            $linked = $current->fetchColumn();
            if ($linked === false) {
                continue; // konečná faktura převedená jinak než jako běžná faktura
            }
            $linked = $linked !== null ? (int) $linked : null;
            if (count($advances) === 1 && count($finalsOf[(int) array_key_first($advances)]) === 1) {
                $advanceId = (int) array_key_first($advances);
                if ($linked === $advanceId) {
                    continue;
                }
                $taken->execute([$ctx->supplierId, $advanceId]);
                if ($linked === null && $taken->fetchColumn() === false) {
                    $set->execute([$advanceId, $finalId, $ctx->supplierId]);
                    if ($set->rowCount() > 0) {
                        $p->count(DocumentLinker::STEP_PAYMENTS, 'advance_linked');
                    }
                    continue;
                }
            }
            foreach ($advances as $advanceId => $amount) {
                if ($linked === $advanceId || $amount < 0.01) {
                    continue;
                }
                $deduct->execute([$ctx->supplierId, $finalId, $advanceId, number_format($amount, 2, '.', ''), $userId]);
                if ($deduct->rowCount() > 0) {
                    $p->count(DocumentLinker::STEP_PAYMENTS, 'advance_deductions');
                }
            }
        }
        $this->linkTaxDocuments($ctx, $ddkp, 'purchase_invoices', 'parent_purchase_invoice_id', 'document_kind', 'vendor_id');
    }

    /**
     * @param array<int,array<int,float>> $deductions
     * @param array<int,array<int,true>> $ddkp
     */
    private function linkIssued(ImportContext $ctx, array $deductions, array $ddkp): void
    {
        $p = $ctx->protocol;
        $set = $this->db->pdo()->prepare(
            "UPDATE invoices SET parent_invoice_id = ? WHERE id = ? AND supplier_id = ? AND parent_invoice_id IS NULL AND invoice_type = 'invoice'"
        );
        foreach ($deductions as $finalId => $advances) {
            if (count($advances) !== 1) {
                $p->count(DocumentLinker::STEP_PAYMENTS, 'advance_multiple');
                continue;
            }
            $set->execute([(int) array_key_first($advances), $finalId, $ctx->supplierId]);
            if ($set->rowCount() > 0) {
                $p->count(DocumentLinker::STEP_PAYMENTS, 'advance_linked');
            }
        }
        $this->linkTaxDocuments($ctx, $ddkp, 'invoices', 'parent_invoice_id', 'invoice_type', 'client_id');
    }

    /** @param array<int,array<int,true>> $ddkp */
    private function linkTaxDocuments(ImportContext $ctx, array $ddkp, string $table, string $column, string $kindColumn, string $partner): void
    {
        $set = $this->db->pdo()->prepare(
            "UPDATE {$table} t JOIN {$table} a ON a.id = ? AND a.supplier_id = t.supplier_id AND a.{$partner} = t.{$partner}
                SET t.{$column} = a.id
              WHERE t.id = ? AND t.supplier_id = ? AND t.{$column} IS NULL AND t.{$kindColumn} = 'tax_document'"
        );
        foreach ($ddkp as $taxDocumentId => $advances) {
            if (count($advances) !== 1) {
                continue;
            }
            $set->execute([(int) array_key_first($advances), $taxDocumentId, $ctx->supplierId]);
            if ($set->rowCount() > 0) {
                $ctx->protocol->count(DocumentLinker::STEP_PAYMENTS, 'tax_document_advance_linked');
            }
        }
    }
}
