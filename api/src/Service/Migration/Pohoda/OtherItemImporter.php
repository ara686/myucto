<?php

declare(strict_types=1);

namespace MyInvoice\Service\Migration\Pohoda;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PohodaImportRepository;
use MyInvoice\Service\Migration\MoneyS3\ImportProtocol;
use PDO;

/**
 * Ostatní pohledávky a závazky POHODY mimo přiznání DPH jako doklady agendy Ostatní
 * pohledávky a závazky. Doklady v přiznání převádí {@see InvoiceImporter} na faktury.
 *
 * Doklad převezme vlastní zápis převedeného deníku (bez nového účtování), jeho kontaci
 * a úhrady z `liquidations`, které po převodu banky a pokladny přiřadí
 * {@see DocumentLinker::matchPayments()}. Převádí se i uhrazené doklady, aby agenda
 * nesla celou historii roku.
 *
 * Nepřevádí se:
 *   - doklad bez vlastního zápisu v deníku (v POHODĚ evidovaný bez zaúčtování, předpis
 *     nese jiný doklad) - ostatní položka by v MyÚčtu neměla co vlastnit,
 *   - předpis mezd, odvodů a daní (účty 331, 336, 341, 342, 343) - vlastní ho mzdový
 *     a daňový modul, v agendě by byl podruhé,
 *   - doklad s jiným tvarem zápisu, než je jeden saldokontní účet proti protiúčtům.
 */
final class OtherItemImporter
{
    public const STEP = 'other_items';

    /** Saldokontní účty, jejichž předpisy vlastní mzdy, DPH a daň z příjmů. */
    private const MODULE_ACCOUNTS = ['331', '336', '341', '342', '343'];

    public function __construct(
        private readonly Connection $db,
        private readonly PohodaImportRepository $map,
        private readonly PartnerImporter $partners,
    ) {}

    public function run(PohodaContext $ctx): void
    {
        $p = $ctx->protocol;
        $mapped = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_OTHER_ITEM);
        $journal = $this->map->all($ctx->supplierId, PohodaImportRepository::KIND_JOURNAL_ENTRY);
        $unposted = [];
        foreach (['receivable' => PohodaJournal::RECEIVABLE, 'commitment' => PohodaJournal::COMMITMENT] as $agenda => $source) {
            $invoiceKind = $agenda === 'receivable'
                ? PohodaImportRepository::KIND_INVOICE
                : PohodaImportRepository::KIND_PURCHASE_INVOICE;
            foreach ($ctx->export->records($agenda, 'invoice') as $record) {
                $header = PohodaXml::get($record, 'invoiceHeader');
                $number = PohodaXml::text($header, 'number/numberRequested');
                $issued = PohodaXml::date($header, 'date') ?? PohodaXml::date($header, 'dateAccounting');
                if ($number === '' || $issued === null || $ctx->skipsDate($issued)) {
                    $p->count(self::STEP, 'skipped');
                    continue;
                }
                $key = $agenda . '|' . $number . '|' . $issued;
                if (isset($mapped[$key])) {
                    // Úhrady se párují i při opakovaném převodu - mohly mezitím přibýt.
                    self::captureLiquidations($ctx, (int) $mapped[$key], $number, $record);
                    $p->count(self::STEP, 'existing');
                    continue;
                }
                if ($this->map->get($ctx->supplierId, $invoiceKind, $key) !== null) {
                    $p->count(self::STEP, 'existing');
                    continue;
                }
                $class = $agenda === 'receivable'
                    ? $ctx->vat->sale(PohodaXml::text($header, 'classificationVAT/ids'), 0.0)
                    : $ctx->vat->purchase(PohodaXml::text($header, 'classificationVAT/ids'));
                if ($class !== null && $class['in_return']) {
                    continue; // faktura z kroku dokladů
                }
                $candidate = self::candidate($record, $agenda, $ctx->vat);
                if ($candidate === null) {
                    $p->count(self::STEP, 'requires_review');
                    $p->warn(self::STEP, 'other_item_review', "Doklad {$number} (" . self::label($agenda) . ') má částky, měnu nebo zálohu, které převod do agendy nepřevezme; zůstává jen v deníku.', ['document_no' => $number]);
                    continue;
                }
                $entries = [];
                foreach ($journal as $journalKey => $entryId) {
                    $parts = explode('|', (string) $journalKey, 4);
                    if (count($parts) === 4 && $parts[1] === $source && $parts[2] === $number
                        && (int) $parts[0] >= $ctx->year() - 1 && (int) $parts[0] <= $ctx->year()) {
                        $entries[(int) $entryId] = true;
                    }
                }
                if ($entries === []) {
                    $p->count(self::STEP, $issued < $ctx->periodStart() ? 'previous_period' : 'unposted');
                    if ($candidate['remaining'] >= 0.005) {
                        $unposted[] = $number . ' ' . number_format($candidate['remaining'], 2, ',', ' ') . ' Kč';
                    }
                    continue;
                }
                if (count($entries) !== 1) {
                    $p->count(self::STEP, 'requires_review');
                    $p->warn(self::STEP, 'other_item_review', "Doklad {$number} (" . self::label($agenda) . ') má v deníku víc zápisů; zůstává jen v deníku.', ['document_no' => $number]);
                    continue;
                }
                $entryId = (int) array_key_first($entries);
                $posting = $this->posting($ctx->supplierId, $entryId, $agenda, $candidate['amount']);
                if ($posting === null) {
                    $p->count(self::STEP, 'requires_review');
                    $p->warn(self::STEP, 'other_item_review', "Doklad {$number} (" . self::label($agenda) . ') má zápis v deníku, který neodpovídá jednomu saldokontnímu účtu proti protiúčtům; zůstává jen v deníku.', ['document_no' => $number]);
                    continue;
                }
                if (self::moduleOwned($posting['account'])) {
                    $p->count(self::STEP, 'payroll_or_tax');
                    continue;
                }
                $snapshot = PartnerImporter::snapshot(PohodaXml::get($header, 'partnerIdentity'));
                $partnerId = $snapshot['name'] !== '' || $snapshot['ico'] !== ''
                    ? $this->partners->resolvePartner($ctx, $snapshot)
                    : null;
                $counter = count($posting['lines']) === 1 ? $posting['lines'][0]['account_code'] : null;
                $pdo = $this->db->pdo();
                try {
                    $insert = $pdo->prepare(
                        'INSERT INTO other_items
                          (supplier_id, side, kind, title, partner_id, partner_name, issued_on, accounting_on, due_on,
                           currency, amount, amount_czk, variable_symbol, account_code, counter_account_code,
                           note, status, document_no, journal_entry_id, posted_at, created_by, updated_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insert->execute([
                        $ctx->supplierId, $agenda === 'receivable' ? 'receivable' : 'payable', 'other',
                        mb_substr($candidate['title'], 0, 255), $partnerId,
                        mb_substr($snapshot['name'], 0, 190) ?: null,
                        $issued, $posting['date'], $candidate['due'], 'CZK', $candidate['amount'],
                        $candidate['amount'], $candidate['symbol'], $posting['account'], $counter,
                        'Převzato z POHODY, doklad ' . $number, 'posted', mb_substr($number, 0, 50),
                        $entryId, $posting['posted_at'], $ctx->userOrNull(), $ctx->userOrNull(),
                    ]);
                } catch (\PDOException $e) {
                    if ((string) $e->getCode() !== '23000') throw $e;
                    $p->warn(self::STEP, 'number_taken', "Doklad {$number} nelze převzít, číslo už má jiná pohledávka nebo závazek.", ['document_no' => $number]);
                    continue;
                }
                $id = (int) $pdo->lastInsertId();
                if ($counter === null) {
                    $line = $pdo->prepare('INSERT INTO other_item_posting_lines
                        (supplier_id, other_item_id, position, account_code, amount) VALUES (?, ?, ?, ?, ?)');
                    foreach ($posting['lines'] as $position => $row) {
                        $line->execute([$ctx->supplierId, $id, $position + 1, $row['account_code'], number_format($row['amount'], 2, '.', '')]);
                    }
                }
                $owner = $pdo->prepare(
                    "UPDATE journal_entries SET source_type = 'other_item', source_id = ?
                       WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL"
                );
                $owner->execute([$id, $entryId, $ctx->supplierId]);
                if ($owner->rowCount() !== 1) {
                    throw new PohodaException('other_item_journal_claim', 'Převzatý zápis deníku už vlastní jiný doklad.');
                }
                $this->map->put($ctx->supplierId, PohodaImportRepository::KIND_OTHER_ITEM, $key, $id, $ctx->runId);
                self::captureLiquidations($ctx, $id, $number, $record);
                $p->count(self::STEP, 'created');
                $p->count(self::STEP, $candidate['remaining'] >= 0.005 ? 'open' : 'settled');
            }
        }
        if ($unposted !== []) {
            $p->info(self::STEP, 'other_item_unposted', count($unposted) . ' neuhrazených ostatních pohledávek a závazků vede POHODA bez zaúčtování '
                . '(předpis je v deníku jiným dokladem). Do agendy se nepřevádějí; k úhradě je zadejte ručně nebo je sledujte v saldokontu.',
                ['documents' => array_slice($unposted, 0, ImportProtocol::LIST_LIMIT)]);
        }
    }

    /**
     * Doklad mimo přiznání DPH v korunách bez odpočtu zálohy, se součtem dokladu shodným
     * s položkami. `remaining` je zbývající částka podle POHODY (úhrady páruje až krok úhrad).
     *
     * @return array{amount:float,remaining:float,due:string,title:string,symbol:?string}|null
     */
    public static function candidate(array $record, string $agenda, PohodaVat $vat): ?array
    {
        if (!in_array($agenda, ['receivable', 'commitment'], true)) return null;
        $header = PohodaXml::get($record, 'invoiceHeader');
        $summary = PohodaXml::get($record, 'invoiceSummary/homeCurrency');
        $code = PohodaXml::text($header, 'classificationVAT/ids');
        $class = $agenda === 'receivable' ? $vat->sale($code, 0.0) : $vat->purchase($code);
        if ($code === '' || $class === null || $class['in_return'] || PohodaXml::text($header, 'MOSS/ids') !== '') return null;
        if (PohodaXml::text($record, 'invoiceSummary/foreignCurrency/currency/ids') !== '') return null;
        if (PohodaXml::all($record, 'invoiceDetail/invoiceAdvancePaymentItem') !== []) return null;
        foreach (['priceLow', 'priceLowVAT', 'priceHigh', 'priceHighVAT', 'price3', 'price3VAT', 'round/priceRound'] as $field) {
            if (abs(PohodaXml::num($summary, $field)) >= 0.005) return null;
        }
        $amount = round(PohodaXml::num($summary, 'priceNone'), 2);
        $summaryTotal = PohodaXml::text($summary, 'priceSum');
        if ($summaryTotal !== '' && (!is_numeric($summaryTotal) || abs((float) $summaryTotal - $amount) >= 0.005)) return null;
        $detailAmount = 0.0;
        $details = PohodaXml::all($record, 'invoiceDetail/invoiceItem');
        foreach ($details as $detail) {
            if (abs(PohodaXml::num($detail, 'homeCurrency/priceVAT')) >= 0.005) return null;
            $detailAmount += PohodaXml::num($detail, 'homeCurrency/price');
        }
        if ($details !== [] && abs($detailAmount - $amount) >= 0.005) return null;
        $remaining = PohodaXml::text($header, 'liquidation/amountHome');
        $remaining = $remaining === '' ? 0.0 : (is_numeric($remaining) ? round((float) $remaining, 2) : null);
        if ($amount <= 0 || $remaining === null || $remaining < -0.005 || $remaining - $amount >= 0.005) return null;
        $due = PohodaXml::date($header, 'dateDue') ?? PohodaXml::date($header, 'date');
        if ($due === null) return null;
        $title = PohodaXml::text($header, 'text');
        $symbol = PohodaXml::text($header, 'symVar');
        return [
            'amount' => $amount,
            'remaining' => max(0.0, $remaining),
            'due' => $due,
            'title' => $title !== '' ? $title : PohodaXml::text($header, 'number/numberRequested'),
            'symbol' => preg_match('/^[0-9]{1,20}$/', $symbol) === 1 ? $symbol : null,
        ];
    }

    private function posting(int $supplierId, int $entryId, string $agenda, float $amount): ?array
    {
        $entry = $this->db->pdo()->prepare(
            "SELECT entry_date, posted_at FROM journal_entries
              WHERE id = ? AND supplier_id = ? AND source_type = 'manual' AND source_id IS NULL
                AND reversed_by IS NULL
                AND NOT EXISTS (SELECT 1 FROM journal_entry_document_links l
                                 WHERE l.supplier_id = journal_entries.supplier_id AND l.entry_id = journal_entries.id)
                AND NOT EXISTS (SELECT 1 FROM other_items oi
                                 WHERE oi.supplier_id = journal_entries.supplier_id AND oi.journal_entry_id = journal_entries.id)"
        );
        $entry->execute([$entryId, $supplierId]);
        $head = $entry->fetch(PDO::FETCH_ASSOC);
        if ($head === false) return null;
        $lines = $this->db->pdo()->prepare(
            'SELECT l.side, l.amount, a.account_code, a.account_type
               FROM journal_entry_lines l
               JOIN chart_of_accounts a ON a.id = l.account_id AND a.supplier_id = l.supplier_id
              WHERE l.entry_id = ? AND l.supplier_id = ? ORDER BY l.line_no, l.id'
        );
        $lines->execute([$entryId, $supplierId]);
        $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
        $accounts = self::postingLines($rows, $agenda, $amount);
        if ($accounts === null) return null;
        return $accounts + ['date' => (string) $head['entry_date'], 'posted_at' => (string) $head['posted_at']];
    }

    /**
     * Kontace dokladu z jeho zápisu v deníku: na straně pohledávky (MD) či závazku (D)
     * jediný rozvahový účet v celé částce dokladu, na druhé straně protiúčty se stejným
     * součtem. Protiúčty se slučují podle účtu v pořadí řádků.
     *
     * @param list<array{side:string,amount:string|float,account_code:string,account_type?:string}> $rows
     * @return array{account:string,lines:list<array{account_code:string,amount:float}>}|null
     */
    public static function postingLines(array $rows, string $agenda, float $amount): ?array
    {
        if (!in_array($agenda, ['receivable', 'commitment'], true) || $rows === []) return null;
        $balanceSide = $agenda === 'receivable' ? 'debit' : 'credit';
        $balanceType = $agenda === 'receivable' ? 'asset' : 'liability';
        $balance = null;
        $balanceSum = 0;
        $counter = [];
        $counterSum = 0;
        foreach ($rows as $row) {
            $code = (string) $row['account_code'];
            $cents = (int) round((float) $row['amount'] * 100);
            if ($row['side'] === $balanceSide) {
                if ($balance !== null && $balance !== $code) return null;
                if (($row['account_type'] ?? $balanceType) !== $balanceType) return null;
                $balance = $code;
                $balanceSum += $cents;
            } else {
                $counter[$code] = ($counter[$code] ?? 0) + $cents;
                $counterSum += $cents;
            }
        }
        $total = (int) round($amount * 100);
        if ($balance === null || $balanceSum !== $total || $counterSum !== $total) return null;
        $lines = [];
        foreach ($counter as $code => $cents) {
            $code = (string) $code;
            if ($code === $balance || preg_match('/^(315|325)(?:$|[.\-])/', $code) === 1 || $cents <= 0) return null;
            $lines[] = ['account_code' => $code, 'amount' => $cents / 100.0];
        }
        return ['account' => $balance, 'lines' => $lines];
    }

    public static function moduleOwned(string $account): bool
    {
        return in_array(substr($account, 0, 3), self::MODULE_ACCOUNTS, true);
    }

    private static function captureLiquidations(PohodaContext $ctx, int $id, string $number, array $record): void
    {
        foreach (PohodaXml::all($record, 'liquidations/liquidation') as $l) {
            $ctx->liquidations[] = [
                'doc' => 'other_item',
                'id' => $id,
                'number' => $number,
                'agenda' => PohodaXml::text($l, 'sourceAgenda'),
                'source' => PohodaXml::text($l, 'sourceDocument/number'),
                'source_id' => PohodaXml::text($l, 'sourceDocument/id'),
                'date' => PohodaXml::date($l, 'date'),
                'amount' => PohodaXml::num($l, 'amount'),
                'liq' => PohodaXml::text($l, 'id') ?: substr(md5((string) json_encode($l)), 0, 12),
            ];
        }
    }

    private static function label(string $agenda): string
    {
        return $agenda === 'receivable' ? 'ostatní pohledávka' : 'ostatní závazek';
    }
}
