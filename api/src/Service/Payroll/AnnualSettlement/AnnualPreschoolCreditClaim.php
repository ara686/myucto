<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\AnnualSettlement;

use DateTimeImmutable;
use JsonSerializable;
use MyInvoice\Service\Payroll\IncomeTax\TaxEvidenceStatus;

/**
 * Nárok na slevu za umístění dítěte (§ 35ba odst. 1 písm. g), § 35bd ZDP ve
 * znění zákona č. 180/2026 Sb., od zdaňovacího období 2027).
 *
 * Jeden nárok = jedno vyživované dítě za rok. Identita dítěte žije
 * v `payroll_dependants`, tady jsou jen údaje z potvrzení předškolního zařízení
 * (§ 38l odst. 2 písm. g) bod 1) a z prohlášení poplatníka (§ 38k odst. 5
 * písm. h). Konstruktor odmítá jen nesmysl (nulové výdaje, nula domácností);
 * podmínky nároku posuzuje {@see conditionFailures()} a jejich nesplnění zastaví
 * zúčtování překážkou, ne uložení formuláře — stejně jako u slevy na manžela.
 *
 * Sleva se při zálohách neuplatňuje (§ 35d odst. 1 ji ve výčtu nemá, § 38h
 * odst. 6), jen v ročním zúčtování po prohlášení do 15. února nebo v přiznání.
 */
final readonly class AnnualPreschoolCreditClaim implements JsonSerializable
{
    /**
     * § 35c odst. 6 a § 35bd odst. 5: vyživované dítě poplatníka. Vnuk jen
     * tehdy, je-li v péči nahrazující péči rodičů.
     */
    private const CHILD_RELATIONS = ['child_own', 'child_adopted', 'child_in_care', 'child_of_spouse'];

    /** Strop počtu domácností; víc v praxi nevznikne a chrání dělení před nesmyslem. */
    public const MAX_HOUSEHOLDS = 9;

    public function __construct(
        public string $childReference,
        public string $childRelation,
        public string $childBirthDate,
        /** Od kdy dítě žije s poplatníkem (evidence vyživovaných osob). */
        public string $childExistenceFrom,
        public ?string $childExistenceTo,
        public AnnualPreschoolFacilityKind $facilityKind,
        public ?string $facilityName,
        /** § 35bd odst. 1: výdaje prokazatelně vynaložené za zdaňovací období. */
        public int $expensesMinorUnits,
        /** § 35bd odst. 2 věta první: počet společně hospodařících domácností, v nichž dítě žije. */
        public int $householdsCount = 1,
        /** § 35bd odst. 2 věta druhá: strop podle písemné dohody poplatníků; `null` = dohoda není. */
        public ?int $agreedCapMinorUnits = null,
        /** § 35bd odst. 3: v téže domácnosti slevu uplatňuje jiný poplatník. */
        public bool $claimedByOtherTaxpayer = false,
        /** § 35bd odst. 4 písm. b): výdaje uplatněné jako výdaj na dosažení příjmů. */
        public bool $claimedAsBusinessExpense = false,
        /** § 35bd odst. 5: vnuk v péči nahrazující péči rodičů. */
        public bool $grandchildCareReplacesParents = false,
        public TaxEvidenceStatus $evidenceStatus = TaxEvidenceStatus::Unverified,
        public ?string $evidenceReference = null,
    ) {
        foreach ([$childBirthDate, $childExistenceFrom] as $date) {
            self::assertDate($date);
        }
        if ($childExistenceTo !== null) {
            self::assertDate($childExistenceTo);
        }
        if ($expensesMinorUnits <= 0) {
            throw new \InvalidArgumentException(
                'Výdaje za umístění dítěte musí být kladná částka.',
            );
        }
        if ($householdsCount < 1 || $householdsCount > self::MAX_HOUSEHOLDS) {
            throw new \InvalidArgumentException(
                'Počet domácností dítěte musí být 1 až ' . self::MAX_HOUSEHOLDS . '.',
            );
        }
        if ($agreedCapMinorUnits !== null && $agreedCapMinorUnits < 0) {
            throw new \InvalidArgumentException('Dohodnutý strop slevy nesmí být záporný.');
        }
    }

    public function isVerified(): bool
    {
        return $this->evidenceStatus === TaxEvidenceStatus::Verified;
    }

    /**
     * Nejvyšší sleva na toto dítě (§ 35bd odst. 1 věta druhá a odst. 2).
     *
     * Bez sdílené péče je to minimální mzda. Žije-li dítě ve více domácnostech,
     * je to podíl minimální mzdy a počtu domácností, nebo strop z písemné
     * dohody; obojí „se zaokrouhluje na celé koruny dolů". Minimální mzda je
     * podle § 21g odst. 1 ta účinná k 1. dni zdaňovacího období.
     */
    public function capMinorUnits(int $minimumWageMinorUnits): int
    {
        if ($this->householdsCount === 1) {
            return $minimumWageMinorUnits;
        }
        $cap = $this->agreedCapMinorUnits ?? intdiv($minimumWageMinorUnits, $this->householdsCount);

        return AnnualPreschoolCreditStatute::floorToCrowns(min($cap, $minimumWageMinorUnits));
    }

    /**
     * Sleva na toto dítě: skutečné výdaje, nejvýš strop.
     *
     * Výsledek je v celých korunách dolů. Sleva snižuje daň, která se podle
     * § 146 odst. 1 daňového řádu zaokrouhluje na celé koruny nahoru; haléřová
     * část slevy by tedy daň stejně nesnížila.
     */
    public function creditMinorUnits(int $minimumWageMinorUnits): int
    {
        return AnnualPreschoolCreditStatute::floorToCrowns(
            min($this->expensesMinorUnits, $this->capMinorUnits($minimumWageMinorUnits)),
        );
    }

    /**
     * Které podmínky § 35bd nejsou splněné. Prázdný seznam = nárok trvá.
     *
     * @return list<string>
     */
    public function conditionFailures(int $taxYear, ?int $minimumWageMinorUnits): array
    {
        $failures = [];
        if (!AnnualPreschoolCreditStatute::isEffective($taxYear)) {
            $failures[] = 'statute_not_effective';
        }
        if ($minimumWageMinorUnits === null || $minimumWageMinorUnits <= 0) {
            $failures[] = 'minimum_wage_unavailable';
        }
        $grandchildInCare = $this->childRelation === 'grandchild' && $this->grandchildCareReplacesParents;
        if (!in_array($this->childRelation, self::CHILD_RELATIONS, true) && !$grandchildInCare) {
            $failures[] = 'child_relation';
        }
        // § 35bd odst. 4 písm. a): dítě žije s poplatníkem „alespoň po část
        // zdaňovacího období".
        $yearStart = sprintf('%04d-01-01', $taxYear);
        $yearEnd = sprintf('%04d-12-31', $taxYear);
        if ($this->childBirthDate > $yearEnd
            || $this->childExistenceFrom > $yearEnd
            || ($this->childExistenceTo !== null && $this->childExistenceTo < $yearStart)
        ) {
            $failures[] = 'child_not_in_household';
        }
        if ($this->claimedByOtherTaxpayer) {
            $failures[] = 'claimed_by_other_taxpayer';
        }
        if ($this->claimedAsBusinessExpense) {
            $failures[] = 'claimed_as_business_expense';
        }
        if ($this->agreedCapMinorUnits !== null) {
            if ($this->householdsCount === 1) {
                $failures[] = 'agreement_without_shared_care';
            } elseif ($minimumWageMinorUnits !== null
                && $this->agreedCapMinorUnits > $minimumWageMinorUnits
            ) {
                $failures[] = 'agreed_cap_over_minimum_wage';
            }
        }

        return $failures;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'child_reference' => $this->childReference,
            'facility_kind' => $this->facilityKind->value,
            'facility_name' => $this->facilityName,
            'expenses_minor_units' => $this->expensesMinorUnits,
            'households_count' => $this->householdsCount,
            'agreed_cap_minor_units' => $this->agreedCapMinorUnits,
            'evidence_status' => $this->evidenceStatus->value,
            'evidence_reference' => $this->evidenceReference,
        ];
    }

    private static function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Datum u slevy za umístění dítěte není platné.');
        }
    }
}
