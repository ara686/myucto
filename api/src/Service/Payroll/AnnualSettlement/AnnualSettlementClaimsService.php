<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\Payroll\PayrollAnnualSettlementRepository;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;

/**
 * Evidence ročně uplatňovaných položek (§ 38h odst. 6): nezdanitelné části
 * základu daně podle § 15, sleva za zastavenou exekuci a sleva na manžela.
 *
 * Ukládá se celá sada za rok najednou, stejně jako potvrzení od předchozích
 * plátců — uplatňuje se jako celek a půlka sady by zúčtování zkreslila.
 *
 * Validace běží přes konstruktory domény, ne přes vlastní pravidla: co se
 * dá uložit, to výpočet také přijme. Doložení se tu nevynucuje (nedoložená
 * položka se uložit smí a zúčtování ji zastaví překážkou); vynucuje se jen to,
 * co hlídají CHECK constrainty, aby neplatný vstup skončil jako 4xx, ne jako
 * chyba databáze.
 */
final class AnnualSettlementClaimsService
{
    private const MAX_DEDUCTIONS = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly PayrollAnnualSettlementRepository $settlements,
    ) {}

    /**
     * Uložené nároky za rok jako doménové objekty i jako řádky pro API.
     *
     * @return array{
     *   claims:list<AnnualNonTaxableClaim>,
     *   spouse:?AnnualSpouseCreditClaim,
     *   deductions:list<array<string,mixed>>,
     *   spouse_row:?array<string,mixed>
     * }
     */
    public function load(int $supplierId, int $employeeId, int $taxYear): array
    {
        $stored = $this->settlements->annualClaimsForYear($supplierId, $employeeId, $taxYear);

        $claims = [];
        $deductionRows = [];
        foreach ($stored['deductions'] as $row) {
            $claims[] = new AnnualNonTaxableClaim(
                'deduction-' . $row['id'],
                AnnualNonTaxableKind::from((string) $row['kind']),
                $row['amount_minor'],
                $row['quantity'],
                $row['months_mask'],
                $row['housing_acquired_before_2021'],
                TaxEvidenceStatus::tryFrom((string) $row['evidence_status'])
                    ?? TaxEvidenceStatus::Unverified,
                self::text($row['evidence_reference'] ?? null),
                self::text($row['counterparty_name'] ?? null),
            );
            $deductionRows[] = [
                'id' => $row['id'],
                'kind' => (string) $row['kind'],
                'amount_minor_units' => $row['amount_minor'],
                'quantity' => $row['quantity'],
                'months_mask' => $row['months_mask'],
                'housing_acquired_before_2021' => $row['housing_acquired_before_2021'],
                'counterparty_name' => self::text($row['counterparty_name'] ?? null),
                'evidence_status' => (string) $row['evidence_status'],
                'evidence_reference' => self::text($row['evidence_reference'] ?? null),
                'note' => self::text($row['note'] ?? null),
            ];
        }

        $spouse = null;
        $spouseRow = null;
        if ($stored['spouse'] !== null) {
            $row = $stored['spouse'];
            $spouse = new AnnualSpouseCreditClaim(
                'dependant-' . $row['spouse_dependant_id'],
                (string) $row['spouse_relation'],
                self::day($row['spouse_existence_from']),
                $row['spouse_existence_to'] === null ? null : self::day($row['spouse_existence_to']),
                (bool) $row['spouse_ztp_p'],
                'dependant-' . $row['child_dependant_id'],
                (string) $row['child_relation'],
                self::day($row['child_birth_date']),
                $row['months_mask'],
                $row['ztp_p_months_mask'],
                $row['spouse_income_minor'],
                TaxEvidenceStatus::tryFrom((string) $row['evidence_status'])
                    ?? TaxEvidenceStatus::Unverified,
                self::text($row['evidence_reference'] ?? null),
            );
            $spouseRow = [
                'spouse_dependant_id' => $row['spouse_dependant_id'],
                'child_dependant_id' => $row['child_dependant_id'],
                'months_mask' => $row['months_mask'],
                'ztp_p_months_mask' => $row['ztp_p_months_mask'],
                'spouse_income_minor_units' => $row['spouse_income_minor'],
                'evidence_status' => (string) $row['evidence_status'],
                'evidence_reference' => self::text($row['evidence_reference'] ?? null),
                'note' => self::text($row['note'] ?? null),
            ];
        }

        return [
            'claims' => $claims,
            'spouse' => $spouse,
            'deductions' => $deductionRows,
            'spouse_row' => $spouseRow,
        ];
    }

    /**
     * Osoby z evidence pro výběr manžela a dítěte (bez rodného čísla).
     *
     * @return list<array<string,mixed>>
     */
    public function dependants(int $supplierId, int $employeeId): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'relation' => (string) $row['relation'],
                'full_name' => (string) $row['full_name'],
                'birth_date' => substr((string) $row['birth_date'], 0, 10),
                'ztp_p' => $row['ztp_p'],
            ],
            $this->settlements->dependantsForEmployee($supplierId, $employeeId),
        );
    }

    /**
     * Přepíše celou sadu nároků za rok.
     *
     * @param list<array<string,mixed>> $deductions
     * @param array<string,mixed>|null $spouse
     * @return array{deductions:list<array<string,mixed>>,spouse:?array<string,mixed>}
     * @throws \InvalidArgumentException neplatný vstup, cizí osoba v evidenci
     */
    public function save(
        int $supplierId,
        int $employeeId,
        int $taxYear,
        array $deductions,
        ?array $spouse,
        ?int $actorUserId,
    ): array {
        if (count($deductions) > self::MAX_DEDUCTIONS) {
            throw new \InvalidArgumentException('Ročních položek je příliš mnoho.');
        }

        $preparedDeductions = [];
        foreach (array_values($deductions) as $index => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Roční položka není platná.');
            }
            $kind = AnnualNonTaxableKind::tryFrom((string) ($row['kind'] ?? ''));
            if ($kind === null) {
                throw new \InvalidArgumentException('Druh roční položky není platný.');
            }
            $status = self::status($row['evidence_status'] ?? null);
            $reference = self::bounded($row['evidence_reference'] ?? null, 500, 'Doklad');
            $counterparty = self::bounded($row['counterparty_name'] ?? null, 191, 'Protistrana');
            $note = self::bounded($row['note'] ?? null, 500, 'Poznámka');
            self::assertEvidence($status, $reference);

            $amount = self::integer($row['amount_minor_units'] ?? null, 'Částka');
            $quantity = self::integer($row['quantity'] ?? null, 'Počet');
            $months = self::integer($row['months_mask'] ?? null, 'Měsíce');
            $housing = self::flag($row['housing_acquired_before_2021'] ?? false);

            $claim = new AnnualNonTaxableClaim(
                'deduction-new-' . ($index + 1),
                $kind,
                $amount,
                $quantity,
                $months,
                $housing,
                $status,
                $reference,
                $counterparty,
            );
            $preparedDeductions[] = [
                'kind' => $claim->kind->value,
                'amount_minor' => $claim->amountMinorUnits,
                'quantity' => $claim->quantity,
                'months_mask' => $claim->monthsMask,
                'housing_acquired_before_2021' => $claim->housingAcquiredBefore2021,
                'counterparty_name' => $claim->counterpartyName,
                'evidence_status' => $claim->evidenceStatus->value,
                'evidence_reference' => $claim->evidenceReference,
                'note' => $note,
            ];
        }

        $preparedSpouse = null;
        if ($spouse !== null) {
            $preparedSpouse = $this->prepareSpouse($supplierId, $employeeId, $spouse);
        }

        $pdo = $this->db->pdo();
        $owns = !$pdo->inTransaction();
        if ($owns) {
            $pdo->beginTransaction();
        }
        try {
            $this->settlements->replaceAnnualClaims(
                $supplierId,
                $employeeId,
                $taxYear,
                $preparedDeductions,
                $preparedSpouse,
                $actorUserId,
            );
            if ($owns) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($owns && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        $loaded = $this->load($supplierId, $employeeId, $taxYear);

        return ['deductions' => $loaded['deductions'], 'spouse' => $loaded['spouse_row']];
    }

    /**
     * @param array<string,mixed> $spouse
     * @return array<string,mixed>
     */
    private function prepareSpouse(int $supplierId, int $employeeId, array $spouse): array
    {
        $spouseId = self::integer($spouse['spouse_dependant_id'] ?? null, 'Manžel/ka');
        $childId = self::integer($spouse['child_dependant_id'] ?? null, 'Dítě');
        if ($spouseId === null || $childId === null || $spouseId <= 0 || $childId <= 0) {
            throw new \InvalidArgumentException('U slevy na manžela je potřeba vybrat manžela i dítě.');
        }
        if ($spouseId === $childId) {
            throw new \InvalidArgumentException('Manžel/ka a dítě musí být různé osoby.');
        }
        // Osoba musí patřit témuž zaměstnanci i firmě; cizí ID se tváří jako neexistující.
        $spouseRow = $this->settlements->findDependant($supplierId, $employeeId, $spouseId);
        $childRow = $this->settlements->findDependant($supplierId, $employeeId, $childId);
        if ($spouseRow === null || $childRow === null) {
            throw new \InvalidArgumentException(
                'Vybraná osoba není v evidenci vyživovaných osob tohoto zaměstnance.',
            );
        }

        $status = self::status($spouse['evidence_status'] ?? null);
        $reference = self::bounded($spouse['evidence_reference'] ?? null, 500, 'Doklad');
        $note = self::bounded($spouse['note'] ?? null, 500, 'Poznámka');
        self::assertEvidence($status, $reference);

        $months = self::integer($spouse['months_mask'] ?? null, 'Měsíce');
        $ztpMonths = self::integer($spouse['ztp_p_months_mask'] ?? 0, 'Měsíce ZTP/P') ?? 0;
        $income = self::integer($spouse['spouse_income_minor_units'] ?? null, 'Příjem manžela');

        // Konstruktor domény odmítne prázdné měsíce a záporný příjem.
        $claim = new AnnualSpouseCreditClaim(
            'dependant-' . $spouseId,
            (string) $spouseRow['relation'],
            self::day($spouseRow['existence_from']),
            $spouseRow['existence_to'] === null ? null : self::day($spouseRow['existence_to']),
            (int) $spouseRow['ztp_p'] === 1,
            'dependant-' . $childId,
            (string) $childRow['relation'],
            self::day($childRow['birth_date']),
            $months ?? 0,
            $ztpMonths,
            $income,
            $status,
            $reference,
        );

        return [
            'spouse_dependant_id' => $spouseId,
            'child_dependant_id' => $childId,
            'months_mask' => $claim->monthsMask,
            'ztp_p_months_mask' => $claim->ztpPMonthsMask,
            'spouse_income_minor' => $claim->spouseIncomeMinorUnits,
            'evidence_status' => $claim->evidenceStatus->value,
            'evidence_reference' => $claim->evidenceReference,
            'note' => $note,
        ];
    }

    private static function status(mixed $value): TaxEvidenceStatus
    {
        if ($value === null || $value === '') {
            return TaxEvidenceStatus::Unverified;
        }
        $status = TaxEvidenceStatus::tryFrom((string) $value);
        if ($status === null) {
            throw new \InvalidArgumentException('Stav doložení není platný.');
        }

        return $status;
    }

    /** Doložená položka nese doklad — stejně jako CHECK v databázi. */
    private static function assertEvidence(TaxEvidenceStatus $status, ?string $reference): void
    {
        if ($status === TaxEvidenceStatus::Verified && $reference === null) {
            throw new \InvalidArgumentException('Doložená položka musí nést odkaz na doklad.');
        }
    }

    private static function bounded(mixed $value, int $max, string $label): ?string
    {
        $text = self::text($value);
        if ($text !== null && mb_strlen($text) > $max) {
            throw new \InvalidArgumentException("{$label} je delší než {$max} znaků.");
        }

        return $text;
    }

    private static function integer(mixed $value, string $label): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('~^-?\d{1,18}$~', $value) === 1) {
            return (int) $value;
        }

        throw new \InvalidArgumentException("{$label} není celé číslo.");
    }

    private static function flag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (int) $value === 1;
        }
        if ($value === null) {
            return false;
        }

        throw new \InvalidArgumentException('Příznak bytové potřeby není platný.');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function day(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }
}
