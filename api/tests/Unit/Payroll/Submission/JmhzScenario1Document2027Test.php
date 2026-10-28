<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAnnualEvidenceService;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshot;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPreparationSnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzPvpojPreview;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1DocumentResolver;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenario1Resolution;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzVerifiedPreparationSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Normalizovaný dokument JMHZ verze 2027 (vykazované období od 01/2027):
 * nové atributy datového slovníku 1.5.0.3 z mzdového jádra a fail-closed
 * nálezy tam, kde jádro podklad nemá.
 */
final class JmhzScenario1Document2027Test extends TestCase
{
    private const SOURCE_MISSING = 'jmhz_2027_source_missing';

    /**
     * Otisk dokumentu 2026 ze stejného přípravku před zavedením verze 2027.
     * Změna znamená, že se změnil výstup za období 2026.
     */
    private const DOCUMENT_2026_SHA256 = 'ebe042f53405c4469bff0cd1eb9284e1538d0b55aaaada809c0c85b6f5843d86';

    public function testDocument2026IsByteIdenticalAndCarriesNo2027Keys(): void
    {
        $resolution = $this->resolve($this->payload('2026-07-01'), '2026-07-01');

        self::assertSame('resolved', $resolution->status());
        $payload = $resolution->candidate?->payload ?? [];
        self::assertArrayNotHasKey('spec_version', $payload);
        self::assertArrayNotHasKey('jmhz_2027', $payload['people'][0]['summary']);
        self::assertArrayNotHasKey('jmhz_2027', $payload['people'][0]['employments'][0]);
        self::assertSame(self::DOCUMENT_2026_SHA256, $resolution->candidate?->sha256());
    }

    /**
     * Bez prohlášení (fixture) jde příjem podle § 6 odst. 4 od 2027 do
     * zálohové daně, jejíž rozdělení po vztazích výpočet zatím nenese:
     * 10555 zůstává neuvedený a dokument stojí. V lednu chybí i úhrn
     * měsíčních bonusů (10577).
     */
    public function testJanuary2027WithoutDeclarationBlocksSection64AndMonthlyBonusTotal(): void
    {
        $resolution = $this->resolve($this->payload('2027-01-01'), '2027-01-01');

        self::assertSame('blocked', $resolution->status());
        self::assertSame(
            [
                [self::SOURCE_MISSING, 'person', 11, ['10535', '10555']],
                [self::SOURCE_MISSING, 'person', 11, ['10577']],
            ],
            array_values(array_filter(
                $this->blockers($resolution),
                static fn (array $blocker): bool => $blocker[0] === self::SOURCE_MISSING,
            )),
        );
        $payload = $resolution->candidate?->payload ?? [];
        self::assertSame('2027', $payload['spec_version']);
        self::assertSame(
            [
                'taxpayer_declaration_signed_on' => null,
                'student_credit_czk' => null,
                'student_doctoral_programme' => null,
                'multi_month_advance' => false,
                'ozp_wage_dates' => null,
                'annual_monthly_bonuses_total_czk' => null,
            ],
            $payload['people'][0]['summary']['jmhz_2027'],
        );
        self::assertSame(
            [
                'section_6_4_income_czk' => null,
                'sickness_countable_income_czk' => 1000,
                'worked_millihours' => null,
                'overtime_millihours' => null,
                'risk_work' => null,
                'state_prosecutor' => null,
                'work_place_address' => null,
            ],
            $payload['people'][0]['employments'][0]['jmhz_2027'],
        );
    }

    /**
     * S prohlášením se § 6 odst. 4 neuplatní: 10555 = 0 (kontrola 304 chce
     * 10535 i 10555 vyplněné). Datum podpisu (10575, IN16) a slevu na
     * studenta (10565, 10566) jádro nemá, takže dokument stojí.
     */
    public function testDeclarantReportsZeroSection64AndBlocksSignatureDateAndStudentCredit(): void
    {
        $payload = $this->payload('2027-04-01');
        $payload['people'][0]['employments'][0]['term']['tax_declaration_signed'] = true;

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertSame(
            [
                [self::SOURCE_MISSING, 'person', 11, ['10565', '10566']],
                [self::SOURCE_MISSING, 'person', 11, ['10575']],
            ],
            $this->blockers($resolution),
        );
        $document = $resolution->candidate?->payload ?? [];
        self::assertSame(0, $document['people'][0]['employments'][0]['jmhz_2027']['section_6_4_income_czk']);
        self::assertNull($document['people'][0]['summary']['jmhz_2027']['taxpayer_declaration_signed_on']);
        self::assertNull($document['people'][0]['summary']['jmhz_2027']['student_credit_czk']);
        self::assertFalse($document['people'][0]['summary']['jmhz_2027']['multi_month_advance']);
    }

    /** Bez spočtené měsíční zálohy se 10579 neuvádí (kontrola 245). */
    public function testMultiMonthAdvanceIsUnsetWithoutCalculatedAdvance(): void
    {
        $payload = $this->payload('2027-04-01');
        unset($payload['people'][0]['person_summary']['statutory']['income_tax']['advance_tax']);

        $resolution = $this->resolve($payload, '2027-04-01');

        $summary2027 = $resolution->candidate?->payload['people'][0]['summary']['jmhz_2027'] ?? [];
        self::assertArrayHasKey('multi_month_advance', $summary2027);
        self::assertNull($summary2027['multi_month_advance']);
    }

    /**
     * 10273 je ve verzi 2027 celé číslo: odpracované hodiny po odečtu
     * náhradního volna, zaokrouhlené nahoru (150,5 h − 8 h = 142,5 h → 143).
     * Přesčas (10269) smí být záporný: 4 h přesčasu − 8 h volna = −4 h.
     * Kategorie 1 (práce kategorie 4) vyžaduje počet směn 10580 (IN42).
     */
    public function testRiskWorkCategoryFourReportsWholeHoursSignedOvertimeAndBlocksShifts(): void
    {
        $payload = $this->payload('2027-04-01');
        $employment = &$payload['people'][0]['employments'][0];
        $employment['term']['social_employer_rate_category'] = 'risk_employment';
        $employment['work_month']['jmhz_work_summary']['values'] = [
            'worked_millihours' => 150_500,
            'overtime_millihours' => 4_000,
            'compensatory_time_off_millihours' => 8_000,
        ];
        unset($employment);

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertContains(
            [self::SOURCE_MISSING, 'employment', 101, ['10580']],
            $this->blockers($resolution),
        );
        $form = $resolution->candidate?->payload['people'][0]['employments'][0]['jmhz_2027'] ?? [];
        self::assertSame(142_500, $form['worked_millihours']);
        self::assertSame(-4_000, $form['overtime_millihours']);
        self::assertSame(
            [['categorization_code' => '1', 'worked_hours' => 143, 'shift_count' => null]],
            $form['risk_work'],
        );
    }

    /** Záchranář (kategorie 6) počet směn nevykazuje (kontrola 386). */
    public function testRescueWorkReportsHoursWithoutShiftBlocker(): void
    {
        $payload = $this->payload('2027-04-01');
        $employment = &$payload['people'][0]['employments'][0];
        $employment['term']['social_employer_rate_category'] = 'rescue_and_company_fire_service';
        $employment['term']['jmhz_risk_categorization_code'] = '6';
        $employment['work_month']['jmhz_work_summary']['values'] = ['worked_millihours' => 160_000];
        unset($employment);

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertNotContains(
            [self::SOURCE_MISSING, 'employment', 101, ['10580']],
            $this->blockers($resolution),
        );
        self::assertSame(
            [['categorization_code' => '6', 'worked_hours' => 160, 'shift_count' => null]],
            $resolution->candidate?->payload['people'][0]['employments'][0]['jmhz_2027']['risk_work'],
        );
    }

    /**
     * Místo výkonu v ČR: plná adresa (10552, 10554 povinné) ve vztahu není.
     * Místo výkonu v zahraničí adresu mít nesmí (kontrola 385).
     */
    public function testDomesticWorkplaceBlocksFullAddressForeignDoesNot(): void
    {
        $domestic = $this->payload('2027-04-01');
        $domestic['people'][0]['employments'][0]['term']['jmhz_workplace_country_code'] = 'CZ';
        $foreign = $this->payload('2027-04-01');
        $foreign['people'][0]['employments'][0]['term']['jmhz_workplace_country_code'] = 'DE';

        self::assertContains(
            [self::SOURCE_MISSING, 'employment', 101, ['10550', '10551', '10552', '10553', '10554']],
            $this->blockers($this->resolve($domestic, '2027-04-01')),
        );
        self::assertSame(
            [[self::SOURCE_MISSING, 'person', 11, ['10535', '10555']]],
            $this->blockers($this->resolve($foreign, '2027-04-01')),
        );
    }

    /** Státní zástupce (10587) je povinný jen ve scénáři K až S. */
    public function testStateProsecutorFlagBlocksOnlyInCorporateBodyScenario(): void
    {
        $payload = $this->payload('2027-04-01');
        $payload['scope']['scenario_set'] = ['scenario_3'];
        $payload['people'][0]['employments'][0]['scenario_resolution'] = ['scenario_key' => 'scenario_3'];

        $blockers = $this->blockers($this->resolve($payload, '2027-04-01'));

        self::assertContains([self::SOURCE_MISSING, 'employment', 101, ['10587']], $blockers);
        self::assertNotContains(
            [self::SOURCE_MISSING, 'employment', 101, ['10587']],
            $this->blockers($this->resolve($this->payload('2027-04-01'), '2027-04-01')),
        );
    }

    /**
     * Výdělek OZP: 10347 se ve verzi 2027 dělí na 10589, 10570 a 10571.
     * Ruční evidence nese jen jedno datum „mzdy včetně pojistného".
     */
    public function testOzpWageBlocksTheThreeSplitDates(): void
    {
        $payload = $this->payload('2027-04-01');
        $payload['ordinary_evidence'][0]['ozp_wage'] = [
            'payment_date' => '2027-04-10',
            'cash_paid' => false,
            'employer_deductions_made' => false,
            'employer_deductions_minor' => [],
        ];

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertContains(
            [self::SOURCE_MISSING, 'person', 11, ['10570', '10571', '10589']],
            $this->blockers($resolution),
        );
        self::assertSame(
            [
                'wage_payment_date' => null,
                'social_insurance_payment_date' => null,
                'health_insurance_payment_date' => null,
            ],
            $resolution->candidate?->payload['people'][0]['summary']['jmhz_2027']['ozp_wage_dates'],
        );
    }

    /** 10476: neúčastný vztah má započitatelný příjem 0, ne neuvedeno. */
    public function testSicknessCountableIncomeIsZeroForNonParticipatingRelationship(): void
    {
        $payload = $this->payload('2027-04-01');
        $payload['people'][0]['employments'][0]['insurance']['participation']['status'] = 'not_participating';

        $resolution = $this->resolve($payload, '2027-04-01');

        self::assertSame(
            0,
            $resolution->candidate?->payload['people'][0]['employments'][0]['jmhz_2027']
                ['sickness_countable_income_czk'],
        );
    }

    /**
     * Datový slovník 1.5.0.3 připouští zápor u rozpadu mzdy (10329 až 10343):
     * ve verzi 2027 se neořezává. Mzda zúčtovaná (10328) zápornost nemá a
     * dál se vykazuje nulou. Ve verzi 2026 se ořezávají obě.
     */
    public function testNegativeWageBreakdownIsSignedOnlyIn2027(): void
    {
        $build = function (string $periodStart): array {
            $payload = $this->payload($periodStart);
            $payload['people'][0]['employments'][0]['earnings_by_attribute_minor']['10328'] = -100_000;
            $payload['people'][0]['employments'][0]['earnings_by_attribute_minor']['10329'] = -100_000;

            return $this->resolve($payload, $periodStart)->candidate?->payload['people'][0]
                ['employments'][0]['earnings_by_attribute_czk'] ?? [];
        };

        $earnings2026 = $build('2026-07-01');
        $earnings2027 = $build('2027-04-01');

        self::assertSame(0, $earnings2026['10328']);
        self::assertSame(0, $earnings2026['10329']);
        self::assertSame(0, $earnings2027['10328']);
        self::assertSame(-1000, $earnings2027['10329']);
    }

    /** Ořez 2027 odpovídá přesně atributům bez „zápornost" ve slovníku 1.5.0.3. */
    public function testClamp2027MatchesDictionarySignedness(): void
    {
        $manifest = (new JmhzSpecPackageCatalog(dirname(__DIR__, 4) . '/resources/payroll/jmhz'))->load(
            JmhzSpecPackageCatalog::PACKAGE_KEY_2027,
            JmhzSpecPackageCatalog::MANIFEST_SHA256_2027,
        );
        $unsigned = [];
        foreach ($manifest['payload']['dictionary_attributes'] as $attribute) {
            $id = $attribute['attribute_id'];
            if (in_array($id, JmhzScenario1DocumentResolver::NEGATIVE_INCOME_REPORTED_AS_ZERO_2027, true)
                || in_array($id, ['10329', '10330', '10331', '10337', '10338', '10269'], true)
            ) {
                $unsigned[$id] = !str_contains((string) $attribute['data_type_refinement'], 'zápornost');
            }
        }
        ksort($unsigned);

        self::assertSame(
            [
                '10269' => false,
                '10286' => true,
                '10328' => true,
                '10329' => false,
                '10330' => false,
                '10331' => false,
                '10337' => false,
                '10338' => false,
                '10344' => true,
            ],
            $unsigned,
        );
    }

    /**
     * Aktualita MPSV 7. 10. 2026: 10311 a 10312 se za leden 2027 nevyplňují.
     * Za leden 2026 se z potvrzení o srážkové dani dál vykazují.
     */
    public function testWithholdingAnnualTotalsAreOmittedInJanuary2027Only(): void
    {
        $january2026 = $this->resolve($this->withAnnualCertificate($this->payload('2026-01-01'), 2025), '2026-01-01');
        $january2027 = $this->resolve($this->withAnnualCertificate($this->payload('2027-01-01'), 2026), '2027-01-01');

        self::assertSame(
            ['paid_income_czk' => 5000, 'withholding_tax_czk' => 750],
            $january2026->candidate?->payload['people'][0]['summary']['annual']['withholding'],
        );
        self::assertNull($january2027->candidate?->payload['people'][0]['summary']['annual']['withholding']);
        // Osoba bez zaměstnání v roce 2026 bonus mít nemohla: bez nálezu 10577.
        self::assertNotContains(
            [self::SOURCE_MISSING, 'person', 11, ['10577']],
            $this->blockers($january2027),
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function withAnnualCertificate(array $payload, int $taxYear): array
    {
        $payload['people'][0]['annual_evidence'] = [
            'tax_year' => $taxYear,
            'request' => null,
            'request_evidence' => [
                'present' => false,
                'proof' => JmhzAnnualEvidenceService::PROOF_NO_EMPLOYMENT_IN_TAX_YEAR,
                'tax_year' => $taxYear,
            ],
            'settlement' => null,
            'settlement_evidence' => [
                'performed' => false,
                'proof' => 'outcome_absent_under_unique_key_lock',
                'tax_year' => $taxYear,
            ],
            'withholding_certificate' => [
                'paid_income_minor_units' => 500_000,
                'withholding_tax_minor_units' => 75_000,
            ],
        ];

        return $payload;
    }

    /** @return list<array{0:string,1:string,2:?int,3:list<string>}> */
    private function blockers(JmhzScenario1Resolution $resolution): array
    {
        return array_map(
            static fn ($blocker): array => [
                $blocker->code,
                $blocker->entityType,
                $blocker->entityId,
                $blocker->attributeIds,
            ],
            $resolution->blockers,
        );
    }

    /** @param array<string,mixed> $payload */
    private function resolve(array $payload, string $periodStart): JmhzScenario1Resolution
    {
        $periodEnd = (new \DateTimeImmutable($periodStart))->modify('last day of this month')->format('Y-m-d');
        $payload['scope']['period_start'] = $periodStart;
        $payload['scope']['period_end'] = $periodEnd;

        return (new JmhzScenario1DocumentResolver())->resolve(
            new JmhzVerifiedPreparationSnapshot(
                501,
                7,
                'test',
                401,
                301,
                1,
                $periodStart,
                $periodEnd,
                'scenario_1',
                JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
                str_repeat('1', 64),
                str_repeat('2', 64),
                str_repeat('3', 64),
                [],
                [
                    'schema_reference' => 'payroll-jmhz-preparation-readiness.v1',
                    'status' => 'source_ready',
                    'issue_count' => 0,
                    'issues' => [],
                    'official_submission_supported' => false,
                ],
                $payload,
            ),
            $this->pvpoj(substr($periodStart, 0, 7)),
        );
    }

    /** @return array<string,mixed> */
    private function payload(string $periodStart): array
    {
        return [
            'schema_reference' => JmhzPreparationSnapshot::CURRENT_SCHEMA_REFERENCE,
            'builder_version' => JmhzPreparationSnapshotBuilder::BUILDER_VERSION,
            'scope' => [
                'supplier_id' => 7,
                'environment' => 'test',
                'run_id' => 401,
                'source_revision_id' => 301,
                'revision_no' => 1,
                'period_start' => $periodStart,
                'scenario_set' => ['scenario_1'],
            ],
            'specification' => [
                'package_key' => 'synthetic-package',
                'spec_manifest_sha256' => str_repeat('a', 64),
                'scenario_catalog_key' => 'synthetic-scenarios',
                'scenario_manifest_sha256' => str_repeat('b', 64),
                'control_catalog_key' => 'synthetic-controls',
                'control_manifest_sha256' => str_repeat('c', 64),
            ],
            'source_revision' => [
                'input_snapshot_hash' => str_repeat('d', 64),
                'result_snapshot_hash' => str_repeat('e', 64),
                'ruleset_manifest_hash' => str_repeat('f', 64),
            ],
            'employer_summary' => [
                'employer' => ['identification_number' => '00000019'],
                'office' => ['social_security_variable_symbol' => '1234567890'],
            ],
            'people' => [[
                'employee_id' => 11,
                'person_summary' => [
                    'totals' => ['jmhz_amount_minor' => 100_000],
                    'statutory' => [
                        'status' => 'calculated',
                        'health_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'employee_contribution_minor_units' => 4_500,
                            'employer_contribution_minor_units' => 9_000,
                        ],
                        'social_insurance' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'capped_assessment_base_minor_units' => 100_000,
                            'employee_contribution_minor_units' => 7_100,
                            'employer_contribution_minor_units' => 24_800,
                        ],
                        'income_tax' => [
                            'status' => 'calculated',
                            'issues' => [],
                            'withholding_tax_minor_units' => 0,
                            'withholding_groups' => [],
                            'claimed_non_refundable_credits_minor_units' => 0,
                            'applied_non_refundable_credits_minor_units' => 0,
                            'claimed_non_refundable_credit_breakdown' => [],
                            'advance_tax' => [
                                'taxable_income_minor_units' => 100_000,
                                'rounded_tax_base_minor_units' => 100_000,
                                'tax_before_credits_minor_units' => 15_000,
                                'non_refundable_credits_minor_units' => 0,
                                'child_credit_minor_units' => 0,
                                'tax_after_credits_minor_units' => 15_000,
                                'tax_bonus_minor_units' => 0,
                            ],
                        ],
                        'net_pay' => [
                            'relationships' => [['relationship_id' => 'employment:101']],
                            'net_before_deductions_minor_units' => 86_500,
                            'deducted_minor_units' => 0,
                            'net_payable_minor_units' => 86_500,
                            'deductions' => [],
                        ],
                    ],
                ],
                'employments' => [[
                    'employment_id' => 101,
                    'identity' => [
                        'person_external_identifier' => ['value' => '1000000001'],
                        'jmhz_employment_external_identifier' => ['value' => '2000000000000000000001'],
                    ],
                    'employment' => ['is_primary' => true],
                    'term' => [
                        'activity_code' => '1',
                        'jmhz_relationship_detail_code' => '1',
                        'tax_declaration_signed' => false,
                    ],
                    'scenario_resolution' => ['scenario_key' => 'scenario_1'],
                    'eldp' => ['confirmation' => ['in03_active' => false, 'in04_active' => false]],
                    'work_month' => [
                        'jmhz_work_summary' => [
                            'interactions' => ['IN07' => false, 'IN08' => false],
                        ],
                    ],
                    'average_earning' => ['average_hourly_minor' => 27_550],
                    'earnings_by_attribute_minor' => [
                        '10328' => 100_000,
                        '10329' => 100_000,
                        '10330' => 0,
                        '10331' => 0,
                    ],
                    'insurance' => [
                        'relationship_id' => 'employment:101',
                        'kind' => 'employment',
                        'participation' => [
                            'relationship_id' => 'employment:101',
                            'status' => 'participates',
                            'participation_income_minor_units' => 100_000,
                        ],
                        'assessment_base_minor_units' => 100_000,
                        'capped_assessment_base_minor_units' => 100_000,
                        'employer_rate_category' => 'ordinary',
                    ],
                ]],
            ]],
            'ordinary_evidence' => [[
                'scope' => ['employee_id' => 11, 'employment_id' => 101],
                'attribute_values' => ['10116' => false, '10546' => false],
            ]],
            'source_versions' => [
                'office_id' => 9,
                'employments' => [],
                'ordinary_evidence' => [[
                    'employment_id' => 101,
                    'id' => 601,
                    'source_manifest_sha256' => str_repeat('4', 64),
                    'snapshot_fingerprint' => str_repeat('5', 64),
                ]],
            ],
            'readiness_issue_codes' => [],
            'readiness_issues' => [],
        ];
    }

    private function pvpoj(string $period): JmhzPvpojPreview
    {
        return new JmhzPvpojPreview(
            7,
            401,
            301,
            1,
            $period,
            [
                'office_id' => 4,
                'code' => 'UC4',
                'name' => 'Mzdová účtárna 4',
                'variable_symbol' => '1234567890',
            ],
            [[
                'office_id' => 4,
                'employee_contribution_minor_units' => 7_100,
                'employer_contribution_minor_units' => 24_800,
                'amount_minor_units' => 31_900,
            ]],
            ['revision_input_hash' => str_repeat('d', 64)],
            [
                'pojistne' => [
                    'zakladZamestnavateleA' => 1_000,
                    'pojistneZamestnavateleA' => 248,
                    'pojistneZamestnance' => 71,
                ],
                'pojistneUhrada' => 319,
            ],
            [['employee_id' => 11]],
        );
    }
}
