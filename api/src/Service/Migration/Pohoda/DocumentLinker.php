<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Infrastructure\Database\TransactionFailure;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Accounting\Activation\OpeningBalanceDocuments;
use MyInvoice\Service\Accounting\FiscalCalendar;
use MyInvoice\Service\Accounting\OtherItemException;
use MyInvoice\Service\Accounting\OtherItemService;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use MyInvoice\Service\Migration\Shared\JournalEntryLinker;
use PDO;

/**
 * Provázání převedených dokladů s převedeným deníkem a úhrad s doklady.
 *
 * Zaúčtování se nepřepočítává: zápis z Pohody dostane `source_id` = id dokladu a vazbu
 * v `journal_entry_document_links` (u banky id pohybu). Páruje se podle čísla dokladu,
 * které nese doklad i řádek deníku (`act:number`).
 *
 * Úhrady nese Pohoda přímo u dokladu (`liquidations`): číslo bankovního nebo pokladního
 * dokladu, datum a částku. Párování je proto přesné - žádné odhadování podle částky.
 */
final class DocumentLinker
{
    public const STEP_LINK = 'link';
    public const STEP_PAYMENTS = 'payments';

    private readonly JournalEntryLinker $entries;

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly OtherItemService $otherItems,
    ) {
        $this->entries = new JournalEntryLinker($db, 'Pohody');
    }

    public function link(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $index = $this->journalIndex($ctx->supplierId);
        $unbooked = [
            'invoice' => $this->unbookedIds('invoices', $ctx->supplierId),
            'purchase_invoice' => $this->unbookedIds('purchase_invoices', $ctx->supplierId),
        ];
        // Pohyby se stejným číslem dokladu (platební brána: úhrada, poplatek a odvod netto)
        // mají v deníku Pohody jediný zápis a vlastnit ho může jen jeden z nich. Dostane ho
        // pohyb, kterým Pohoda hradí doklad - jinak by úhrada faktury zůstala mezi
        // nezaúčtovanými pohyby, ačkoli její zápis v deníku je.
        $paying = [];
        foreach ($ctx->liquidations as $l) {
            if ($l['agenda'] === 'bank' && ($l['source_id'] ?? '') !== '' && isset($ctx->bankByPohodaId[$l['source_id']])) {
                $paying[$ctx->bankByPohodaId[$l['source_id']]] = true;
            }
        }
        $bankDocs = [];
        foreach ($ctx->bankTransactions as $number => $txs) {
            usort($txs, static fn (array $a, array $b): int => isset($paying[$b['id']]) <=> isset($paying[$a['id']]));
            foreach ($txs as $tx) {
                $bankDocs[$number . '#' . $tx['date'] . '#' . $tx['id']] = $tx['id'];
            }
        }
        $orphans = [];
        foreach ([
            [PohodaJournal::RECEIVED, 'purchase_invoice', 'purchase_invoice', $ctx->purchaseInvoices, null],
            [PohodaJournal::COMMITMENT, 'manual', 'purchase_invoice', $ctx->commitments, 'purchase_invoice'],
            [PohodaJournal::ISSUED, 'invoice', 'invoice', $ctx->issuedInvoices, null],
            [PohodaJournal::RECEIVABLE, 'manual', 'invoice', $ctx->receivables, 'invoice'],
            [PohodaJournal::INTERNAL, 'manual', 'invoice', $ctx->internalSales, 'invoice'],
            [PohodaJournal::INTERNAL, 'manual', 'purchase_invoice', $ctx->internalPurchases, 'purchase_invoice'],
            [PohodaJournal::CASH, 'cash', 'cash', $ctx->cashDocuments, null],
            [PohodaJournal::BANK, 'bank', 'bank', $bankDocs, null],
        ] as [$source, $sourceType, $docType, $docs, $retype]) {
            foreach ($docs as $docKey => $docId) {
                [$number, $date] = array_pad(explode('#', (string) $docKey, 3), 2, null);
                $entries = $index[$source][$number] ?? [];
                if ($docType === 'bank' && count($entries) > 1 && $date !== null) {
                    $sameDay = array_values(array_filter($entries, static fn (array $e): bool => $e['date'] === $date));
                    $entries = $sameDay !== [] ? $sameDay : $entries;
                }
                $entryIds = array_column($entries, 'id');
                if ($entryIds === []) {
                    if (isset($ctx->previousPeriod[$docType . '|' . $docId])) {
                        $p->count(self::STEP_LINK, 'previous_period');
                        $this->linkOpening($ctx, $docType, (int) $docId);
                    } elseif ($docType === 'cash' && isset($ctx->openingCash[(int) $docId])) {
                        // Pokladní doklad počátečního stavu: zápis nemá, naváže se na otevírací zápis níže.
                    } elseif (isset($unbooked[$docType][$docId])) {
                        $p->count(self::STEP_LINK, 'unbooked');
                    } elseif ($docType === 'bank') {
                        // Pohyb s předkontací „Nevím" - řeší ho krok úhrad ({@see UnbookedBankPayments}).
                        $p->count(self::STEP_LINK, 'unbooked_bank');
                    } else {
                        if (count($orphans) < ImportProtocol::LIST_LIMIT) {
                            $orphans[] = ['type' => $docType, 'document_no' => $number, 'id' => $docId];
                        }
                        $p->count(self::STEP_LINK, 'orphans');
                    }
                    continue;
                }
                $new = $this->entries->attach($ctx->supplierId, $ctx->userOrNull(), $retype ?? $sourceType, $sourceType, $docType, $docId, $entryIds);
                $p->count(self::STEP_LINK, $new ? 'linked' : 'existing');
            }
        }
        foreach (array_keys($ctx->openingCash) as $cashId) {
            if ($this->linkOpening($ctx, 'cash', (int) $cashId)) {
                $p->count(self::STEP_LINK, 'opening_cash');
            }
        }
        $p->set('orphans', $orphans);
        if ($orphans !== []) {
            $p->warn(self::STEP_LINK, 'orphan_documents', count($orphans) . ' dokladů nemá v deníku Pohody zápis se stejným číslem. '
                . 'Nejsou zaúčtované; zaúčtujte je ručně nebo v Účetnictví → Doúčtovat doklady.', ['documents' => array_slice($orphans, 0, 50)]);
        }
        $this->entries->refreshDescriptions($ctx->supplierId, $p, self::STEP_LINK);
        $p->finish(self::STEP_LINK);
    }

    public function matchPayments(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $pdo = $this->db->pdo();
        $existing = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT);
        $insertMatch = $pdo->prepare(
            'INSERT INTO payment_matches (supplier_id, bank_transaction_id, invoice_id, purchase_invoice_id, amount, match_type, matched_by_user_id)
             VALUES (?, ?, ?, ?, ?, "manual", ?)'
        );
        $markTx = $pdo->prepare(
            "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                SET t.match_status = 'manual', t.matched_at = NOW(), t.matched_by = ?, t.matched_invoice_id = COALESCE(?, t.matched_invoice_id)
              WHERE t.id = ? AND s.supplier_id = ?"
        );
        $sameMatch = $pdo->prepare(
            'SELECT id FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ? AND invoice_id <=> ? AND purchase_invoice_id <=> ? LIMIT 1'
        );
        $txMatches = $pdo->prepare('SELECT id FROM payment_matches WHERE supplier_id = ? AND bank_transaction_id = ?');
        // Párování, která založil převod (tento i dřívější běh) - ostatní přidal uživatel.
        $fromPohoda = array_fill_keys(array_map('intval', array_values($existing)), true);
        $start = $ctx->periodStart();
        $cashLink = $pdo->prepare('SELECT invoice_id, purchase_invoice_id FROM cash_documents WHERE id = ? AND supplier_id = ?');
        $cashMulti = [];

        foreach ($ctx->liquidations as $l) {
            $mapKey = $l['doc'] . '|' . $l['id'] . '|' . $l['liq'];
            if (isset($existing[$mapKey])) {
                $p->count(self::STEP_PAYMENTS, 'existing');
                continue;
            }
            $isIssued = $l['doc'] === 'invoice';
            if ($ctx->skipsDate($l['date'])) {
                // Úhrada v roce, který se nepřevádí - pohyb v převodu není, doklad zůstává neuhrazený.
                $p->count(self::STEP_PAYMENTS, 'later_year_skipped');
                continue;
            }
            if ($l['date'] !== null && $l['date'] < $start) {
                // Úhrada v minulém roce - pohyb je v agendě minulého roku, ne v tomto exportu.
                $p->count(self::STEP_PAYMENTS, 'previous_period');
                continue;
            }
            if ($l['doc'] === 'other_item') {
                $this->allocateOtherItem($ctx, $l, $mapKey);
                continue;
            }
            if ($l['doc'] === 'tax_advance') {
                $this->matchTaxAdvance($ctx, $l, $mapKey);
                continue;
            }
            if ($l['agenda'] === 'bank') {
                $txId = $this->bankTransactionFor($ctx, $l);
                if ($txId === null) {
                    continue;
                }
                // Úhradu mohl mezi převody spárovat uživatel v MyÚčtu (Přepárovat u výpisu).
                // Stejná dvojice pohyb + doklad se nevkládá podruhé a k ručnímu párování se nepřidává.
                $sameMatch->execute([$ctx->supplierId, $txId, $isIssued ? $l['id'] : null, $isIssued ? null : $l['id']]);
                $already = $sameMatch->fetchColumn();
                if ($already !== false) {
                    $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, (int) $already, $ctx->runId);
                    $p->count(self::STEP_PAYMENTS, 'already_matched');
                    continue;
                }
                $txMatches->execute([$ctx->supplierId, $txId]);
                $byUser = array_diff_key(array_fill_keys(array_map('intval', $txMatches->fetchAll(PDO::FETCH_COLUMN)), true), $fromPohoda);
                if ($byUser !== []) {
                    $p->count(self::STEP_PAYMENTS, 'transaction_consumed');
                    $p->warn(self::STEP_PAYMENTS, 'transaction_consumed', "Úhrada {$l['source']} dokladu {$l['number']}: pohyb je v MyÚčtu už spárovaný ručně s jiným dokladem, úhrada nepřevzata.", ['document_no' => $l['number']]);
                    continue;
                }
                $insertMatch->execute([
                    $ctx->supplierId, $txId, $isIssued ? $l['id'] : null, $isIssued ? null : $l['id'],
                    number_format(abs($l['amount']), 2, '.', ''), $ctx->userOrNull(),
                ]);
                $matchId = (int) $pdo->lastInsertId();
                $fromPohoda[$matchId] = true;
                $markTx->execute([$ctx->userOrNull(), $isIssued ? $l['id'] : null, $txId, $ctx->supplierId]);
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, $matchId, $ctx->runId);
                $p->count(self::STEP_PAYMENTS, 'bank');
                continue;
            }
            if ($l['agenda'] === 'voucher') {
                $cashId = $ctx->cashDocuments[$l['source']] ?? null;
                if ($cashId === null) {
                    $p->count(self::STEP_PAYMENTS, 'not_found');
                    $p->warn(self::STEP_PAYMENTS, 'payment_not_found', "Úhrada {$l['source']} dokladu {$l['number']} v převedené pokladně není.", ['document_no' => $l['number']]);
                    continue;
                }
                // Pokladní doklad nese vazbu jen na jeden doklad. Hradí-li víc faktur, zůstane
                // vazba na první a ostatní se nepřepíšou; faktury jsou uhrazené podle Pohody.
                $cashLink->execute([$cashId, $ctx->supplierId]);
                $linked = $cashLink->fetch(\PDO::FETCH_ASSOC) ?: [];
                $current = $isIssued ? ($linked['invoice_id'] ?? null) : ($linked['purchase_invoice_id'] ?? null);
                if (($linked['invoice_id'] ?? null) !== null || ($linked['purchase_invoice_id'] ?? null) !== null) {
                    if ($current === null || (int) $current !== (int) $l['id']) {
                        $cashMulti[$l['source']] = true;
                        $p->count(self::STEP_PAYMENTS, 'cash_multi_invoice');
                    }
                    $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, $cashId, $ctx->runId);
                    $p->count(self::STEP_PAYMENTS, 'cash');
                    continue;
                }
                $pdo->prepare(
                    'UPDATE cash_documents SET ' . ($isIssued ? 'invoice_id' : 'purchase_invoice_id') . ' = ?, purpose = ? WHERE id = ? AND supplier_id = ?'
                )->execute([$l['id'], $isIssued ? 'invoice_payment' : 'purchase_payment', $cashId, $ctx->supplierId]);
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, $cashId, $ctx->runId);
                $p->count(self::STEP_PAYMENTS, 'cash');
                continue;
            }
            // Zápočet interním dokladem, odpočet zálohy apod. - úhrada bez pohybu peněz.
            $p->count(self::STEP_PAYMENTS, $l['agenda'] !== '' ? 'other_' . $l['agenda'] : 'without_document');
        }
        $this->markBookedWithoutDocument($ctx);
        if ($cashMulti !== []) {
            $p->info(self::STEP_PAYMENTS, 'cash_multi_invoice', count($cashMulti) . ' pokladních dokladů hradí víc faktur; pokladní doklad ukazuje vazbu jen na první, faktury jsou uhrazené podle Pohody.',
                ['documents' => array_slice(array_keys($cashMulti), 0, 50)]);
        }
        $p->finish(self::STEP_PAYMENTS);
    }

    /**
     * Pohyb, kterým POHODA doklad uhradila. Úhrada nese id bankovního dokladu POHODY - to je
     * jednoznačné i tam, kde se čísla dokladů opakují (číselná řada banky se každý rok
     * začíná znovu).
     *
     * @param array<string,mixed> $l
     */
    private function bankTransactionFor(PohodaContext $ctx, array $l): ?int
    {
        $p = $ctx->protocol;
        $byId = ($l['source_id'] ?? '') !== '' ? ($ctx->bankByPohodaId[$l['source_id']] ?? null) : null;
        $candidates = $byId !== null ? [['id' => $byId, 'date' => $l['date']]] : ($ctx->bankTransactions[$l['source']] ?? []);
        if (count($candidates) > 1) {
            $sameDay = array_values(array_filter($candidates, static fn (array $c): bool => $c['date'] === $l['date']));
            $candidates = count($sameDay) === 1 ? $sameDay : $candidates;
        }
        if (count($candidates) !== 1) {
            $p->count(self::STEP_PAYMENTS, $candidates === [] ? 'not_found' : 'ambiguous');
            if ($candidates === []) {
                $p->warn(self::STEP_PAYMENTS, 'payment_not_found', "Úhrada {$l['source']} dokladu {$l['number']} v převedené bance není.", ['document_no' => $l['number']]);
            }
            return null;
        }
        return (int) $candidates[0]['id'];
    }

    /**
     * Úhrada ostatní pohledávky či závazku: alokace bankovního pohybu nebo pokladního
     * dokladu přes {@see OtherItemService::allocate()}, který ověří volnou částku, směr
     * a zaúčtování platby na saldokontní účet položky. Zápis platby převzala banka či
     * pokladna z deníku POHODY, nic se neúčtuje.
     *
     * @param array<string,mixed> $l
     */
    private function allocateOtherItem(PohodaContext $ctx, array $l, string $mapKey): void
    {
        $p = $ctx->protocol;
        $amount = round(abs((float) $l['amount']), 2);
        if ($l['agenda'] === 'bank') {
            $txId = $this->bankTransactionFor($ctx, $l);
            if ($txId === null) {
                return;
            }
            $input = ['bank_transaction_id' => $txId, 'amount' => $amount];
        } elseif ($l['agenda'] === 'voucher') {
            $cashId = $ctx->cashDocuments[$l['source']] ?? null;
            if ($cashId === null) {
                $p->count(self::STEP_PAYMENTS, 'not_found');
                $p->warn(self::STEP_PAYMENTS, 'payment_not_found', "Úhrada {$l['source']} dokladu {$l['number']} v převedené pokladně není.", ['document_no' => $l['number']]);
                return;
            }
            $input = ['cash_document_id' => (int) $cashId, 'amount' => $amount];
        } else {
            // Zápočet interním dokladem apod. - úhrada bez pohybu peněz, alokace jen z banky či pokladny.
            $p->count(self::STEP_PAYMENTS, 'other_item_' . ($l['agenda'] !== '' ? $l['agenda'] : 'without_document'));
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT pohoda_other_item_payment');
        try {
            $this->otherItems->allocate($ctx->supplierId, (int) $l['id'], $input, $ctx->userOrNull());
            $pdo->exec('RELEASE SAVEPOINT pohoda_other_item_payment');
        } catch (OtherItemException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_other_item_payment');
            $pdo->exec('RELEASE SAVEPOINT pohoda_other_item_payment');
            $p->count(self::STEP_PAYMENTS, 'other_item_not_allocated');
            $p->warn(self::STEP_PAYMENTS, 'other_item_payment', "Úhradu {$l['source']} dokladu {$l['number']} nešlo přiřadit: {$e->getMessage()}", ['document_no' => $l['number']]);
            return;
        }
        $column = isset($input['bank_transaction_id']) ? 'bank_transaction_id' : 'cash_document_id';
        $allocation = $pdo->prepare("SELECT id FROM other_item_allocations WHERE supplier_id = ? AND other_item_id = ? AND {$column} = ?");
        $allocation->execute([$ctx->supplierId, (int) $l['id'], $input[$column]]);
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, (int) $allocation->fetchColumn(), $ctx->runId);
        $p->count(self::STEP_PAYMENTS, 'other_item_' . ($column === 'bank_transaction_id' ? 'bank' : 'cash'));
    }

    /**
     * Úhrada zálohy na daň z příjmů převzaté jako předpis daňového modulu: vazba předpisu na
     * bankovní pohyb (jako po spárování v MyÚčtu). Uhrazenou částku a stav nese předpis už
     * z POHODY; zápis platby převzala banka z deníku, nic se neúčtuje.
     *
     * @param array<string,mixed> $l
     */
    private function matchTaxAdvance(PohodaContext $ctx, array $l, string $mapKey): void
    {
        $p = $ctx->protocol;
        if ($l['agenda'] !== 'bank') {
            $p->count(self::STEP_PAYMENTS, 'tax_advance_' . ($l['agenda'] !== '' ? $l['agenda'] : 'without_document'));
            return;
        }
        $txId = $this->bankTransactionFor($ctx, $l);
        if ($txId === null) {
            return;
        }
        $pdo = $this->db->pdo();
        $pdo->exec('SAVEPOINT pohoda_tax_advance_payment');
        try {
            $stmt = $pdo->prepare(
                "UPDATE tax_advance_schedules s
                   JOIN bank_transactions t ON t.id = ?
                    SET s.matched_transaction_id = t.id, s.paid_source = 'bank', s.match_confidence = 'exact',
                        s.paid_on = DATE(t.posted_at)
                  WHERE s.id = ? AND s.supplier_id = ? AND s.status = 'paid' AND s.matched_transaction_id IS NULL"
            );
            $stmt->execute([$txId, (int) $l['id'], $ctx->supplierId]);
            $pdo->exec('RELEASE SAVEPOINT pohoda_tax_advance_payment');
        } catch (\PDOException $e) {
            if (TransactionFailure::rollbackIfAborted($pdo, $e)) {
                throw $e;
            }
            if ((string) $e->getCode() !== '23000' && (string) $e->getCode() !== '45000') {
                throw $e;
            }
            $pdo->exec('ROLLBACK TO SAVEPOINT pohoda_tax_advance_payment');
            $pdo->exec('RELEASE SAVEPOINT pohoda_tax_advance_payment');
            $p->count(self::STEP_PAYMENTS, 'tax_advance_not_matched');
            $p->warn(self::STEP_PAYMENTS, 'tax_advance_payment', "Úhradu {$l['source']} zálohy {$l['number']} nešlo navázat, pohyb už je spárovaný jinde.", ['document_no' => $l['number']]);
            return;
        }
        $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_PAYMENT, $mapKey, (int) $l['id'], $ctx->runId);
        $p->count(self::STEP_PAYMENTS, $stmt->rowCount() === 1 ? 'tax_advance_bank' : 'tax_advance_kept');
    }

    /**
     * Doklad zastoupený v počátečních stavech (neuhrazený doklad minulého roku, pokladní
     * doklad počátečního stavu) dostane vazbu na otevírací zápis období. Zápis vlastní mít
     * nesmí a Doúčtování dokladů ho podle té vazby vynechá ({@see OpeningBalanceDocuments}).
     */
    private function linkOpening(PohodaContext $ctx, string $docType, int $docId): bool
    {
        return $this->entries->linkOpening($ctx->supplierId, $ctx->userOrNull(), $ctx->period !== null ? (int) $ctx->period['id'] : null, $docType, $docId);
    }

    /**
     * Pohyby, které Pohoda zaúčtovala bez dokladu (poplatky, převody mezi vlastními účty,
     * mzdy, odvody, ostatní závazky mimo DPH), jsou vyřízené - v MyÚčtu pro ně faktura
     * není a výpis by jinak trvale svítil jako nedopárovaný. Označí se jako ignorované
     * s poznámkou; uživatel to může u pohybu vrátit. Spárované úhrady se nemění.
     *
     * Pohyb BEZ zápisu v deníku Pohody (předkontace „Nevím", zaúčtuje se později) vyřízený
     * není - typicky je to nezlikvidovaná úhrada faktury. Zůstane nespárovaný, aby ho
     * párování plateb (Přepárovat u výpisu) mohlo přiřadit k faktuře. Takový pohyb, který
     * dřívější převod omylem označil jako vyřízený, se tady vrátí mezi nespárované.
     */
    private function markBookedWithoutDocument(PohodaContext $ctx): void
    {
        $ids = $this->bankBooking($ctx);
        $pdo = $this->db->pdo();
        $marked = $this->entries->markBankBookedWithoutDocument(
            $ctx->supplierId, $ctx->userOrNull(), $ids['booked'], 'pohoda_booked', 'Zaúčtováno v Pohodě bez dokladu (převod z POHODY).',
        );
        $released = 0;
        foreach (array_chunk(array_values(array_unique($ids['unbooked'])), 500) as $chunk) {
            $stmt = $pdo->prepare(
                "UPDATE bank_transactions t JOIN bank_statements s ON s.id = t.statement_id
                    SET t.match_status = 'unmatched', t.match_reason = NULL, t.matched_at = NULL, t.matched_by = NULL, t.ignore_note = NULL
                  WHERE s.supplier_id = ? AND t.match_status = 'ignored' AND t.match_reason = 'pohoda_booked'
                    AND NOT EXISTS (SELECT 1 FROM payment_matches pm WHERE pm.bank_transaction_id = t.id)
                    AND t.id IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')'
            );
            $stmt->execute(array_merge([$ctx->supplierId], $chunk));
            $released += $stmt->rowCount();
        }
        if ($marked > 0) {
            $ctx->protocol->count(self::STEP_PAYMENTS, 'booked_without_document', $marked);
        }
        if ($released > 0) {
            $ctx->protocol->count(self::STEP_PAYMENTS, 'unbooked_released', $released);
        }
        if ($ids['unbooked'] !== []) {
            $ctx->protocol->info(self::STEP_PAYMENTS, 'unbooked_bank', count($ids['unbooked']) . ' bankovních pohybů nemá v deníku Pohody zápis '
                . '(předkontace „Nevím“). Jednoznačné úhrady faktur mezi nimi převod spároval a zaúčtoval; ostatní zůstávají k párování '
                . '(návrhy a Přepárovat u výpisu) a k zaúčtování (Účetnictví → Doúčtovat doklady).');
        }
    }

    /**
     * Převedené pohyby podle toho, zda je POHODA zaúčtovala (v deníku je zápis banky se
     * stejným číslem dokladu), nebo mají předkontaci „Nevím" a zápis nemají. Jediné místo
     * toho rozhodnutí - řídí se jím vyřízení pohybů bez dokladu i odvozené úhrady
     * ({@see UnbookedBankPayments}).
     *
     * @return array{booked:list<int>,unbooked:list<int>}
     */
    public function bankBooking(PohodaContext $ctx): array
    {
        // Jen deník téže agendy: číselná řada banky se v POHODĚ opakuje po letech a pohyb
        // zaúčtovaný v jiné agendě by jinak vypadal jako zaúčtovaný i tady.
        $booked = $this->journalIndex($ctx->supplierId, $ctx->year(), $ctx->calendar)[PohodaJournal::BANK] ?? [];
        $ids = ['booked' => [], 'unbooked' => []];
        foreach ($ctx->bankTransactions as $number => $txs) {
            foreach ($txs as $tx) {
                $ids[($booked[(string) $number] ?? []) !== [] ? 'booked' : 'unbooked'][] = (int) $tx['id'];
            }
        }
        return ['booked' => array_values(array_unique($ids['booked'])), 'unbooked' => array_values(array_unique($ids['unbooked']))];
    }

    /**
     * Zápisy deníku převedené z Pohody: zdroj → číslo dokladu → zápisy v pořadí data.
     * Staví se z mapy převodu, takže funguje i pro zápisy z dřívějšího běhu.
     *
     * @return array<string,array<string,list<array{date:string,id:int}>>>
     */
    private function journalIndex(int $supplierId, ?int $year = null, ?FiscalCalendar $calendar = null): array
    {
        $index = [];
        $yearStart = $year !== null ? ($calendar ?? FiscalCalendar::calendar())->periodStart($year) : '';
        foreach ($this->map->all($supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY) as $key => $entryId) {
            $parts = explode('|', (string) $key);
            if (count($parts) < 4 || $parts[2] === '') {
                continue; // otevírací zápis "rok|PS"
            }
            // Deník agendy vede i doklady po konci jejího roku, takže zápis roku `$year`
            // mohla přinést už agenda minulého roku.
            if ($year !== null && (int) $parts[0] !== $year
                && !((int) $parts[0] === $year - 1 && ($parts[3] ?? '') >= $yearStart)) {
                continue;
            }
            [, $source, $number, $date] = $parts;
            $index[$source][$number][$date . '|' . str_pad((string) $entryId, 12, '0', STR_PAD_LEFT)] = ['date' => $date, 'id' => $entryId];
        }
        foreach ($index as &$docs) {
            foreach ($docs as &$entries) {
                ksort($entries);
                $entries = array_values($entries);
            }
        }
        return $index;
    }

    /**
     * Doklady, u kterých zápis v deníku nečekáme: koncept k ruční kontrole, proforma
     * a přijatá záloha (Pohoda je neúčtuje).
     *
     * @return array<int,true>
     */
    private function unbookedIds(string $table, int $supplierId): array
    {
        $advance = $table === 'invoices' ? "invoice_type = 'proforma'" : "document_kind = 'advance'";
        $stmt = $this->db->pdo()->prepare("SELECT id FROM {$table} WHERE supplier_id = ? AND (status = 'draft' OR {$advance})");
        $stmt->execute([$supplierId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }
}
