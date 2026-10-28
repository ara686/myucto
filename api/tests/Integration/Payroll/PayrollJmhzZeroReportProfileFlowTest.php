<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeProjection;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzAttributeScope;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzZeroReportProfile;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Řádný formulář s nulovými hodnotami porovnaný atribut po atributu
 * s tabulkou Pravidel podání JMHZ 1.4.5, kap. 1 (ř. 660-747): „PPV je
 * v JMHZ registrováno, ale výkon práce v daném měsíci neprobíhal a ani
 * nebyl zúčtován příjem ze závislé činnosti“.
 *
 * Tabulka je v {@see JmhzZeroReportProfile}; tady se jen převádí její
 * pravidla na predikáty nad XML, které aplikace skutečně sestaví celou
 * cestou účetní (vztah, docházka, běh, příprava, dry-run s XSD
 * a katalogem kontrol). Predikáty jsou jen v testu, produkční kód nulové
 * hlášení dál skládá z dat.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhzZeroReportProfileFlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2026-07';
    private const PERIOD_START = '2026-07-01';
    private const PERIOD_END = '2026-07-31';
    private const PAYDAY = '2026-08-14';

    /**
     * Fond pracovní doby (10259 až 10261) se u zaměstnance v evidenčním
     * stavu vykazuje podle Pokynů k vyplnění MH 1.4.14 (10259: stanovená
     * pracovní doba v měsíci, 10260: sjednaná, 10261: týdenní), i když
     * v měsíci nepracoval. Tabulka nulového hlášení v Pravidlech podání
     * uvádí 0. Rozpor obou podkladů je samostatný nález; do rozhodnutí
     * zůstávají řádky norem pro fond otevřené a test drží dnešní hodnoty
     * podle Pokynů, aby změna nepřišla nepozorovaně.
     */
    private const FUND_ATTRIBUTES = ['10259', '10260', '10261'];

    /**
     * Pojistné na sociální zabezpečení (10481, 10370) serializér u dohody
     * bez účasti na pojištění nevypisuje vůbec (chybí vyměřovací základ
     * 10477). Pokyny k vyplnění i tabulka nulového hlášení je vedou jako
     * povinné s hodnotou 0. Týká se každé dohody bez účasti, nejen nulového
     * měsíce, takže je to samostatný nález mimo nulové hlášení; test drží
     * dnešní stav.
     */
    private const AGREEMENT_SOCIAL_ATTRIBUTES = ['10481', '10370'];

    private int $officeId;
    private int $baseComponentId;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ0P', 'Syntetická registrace JMHZ', '1100004322');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_NULA_PROFIL', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    /**
     * HPP na rodičovské dovolené celý měsíc: vztah trvá, kód ELDP existuje
     * (1++), dny pojištění i vyměřovací základ jsou nula.
     */
    public function testEmployeeOnParentalLeaveMatchesRegularZeroTable(): void
    {
        $person = $this->hireEmployee('Hana Rodičovská', 'female', '1991-04-04');
        $this->createApprovedAbsence($person['employment_id'], 'parental', '2026-06-01', '2026-12-31');
        $response = $this->approveTimeMonth($person['employment_id'], self::PERIOD, []);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        $projection = JmhzAttributeProjection::fromXml($this->submission('parental'));

        self::assertCount(1, $projection->forms());
        $form = $projection->forms()[0];
        self::assertSame('1++', $form->value('10240'));
        $this->assertSubmissionHeader($projection);
        $this->assertRegularZeroTable($form, self::FUND_ATTRIBUTES);
        self::assertSame('184.000', $form->value('10259'));
        self::assertSame('184.000', $form->value('10260'));
        self::assertSame('40.00', $form->value('10261'));
    }

    /**
     * DPP bez práce a bez příjmu (0 Kč výslovně u základní složky): kód
     * ELDP se neuvádí, takže se neuvádí ani platnost kódu a vyměřovací
     * základ.
     */
    public function testAgreementWithoutWorkMatchesRegularZeroTable(): void
    {
        $person = $this->hireAgreement('Dana Dohodová', 'female', '1994-02-02');
        $response = $this->approveTimeMonth($person['employment_id'], self::PERIOD, [], dailyMinutes: 240);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->createApprovedInput($person, $this->baseComponentId, 0, 'zero-profile-' . $person['employment_id'], self::PERIOD_START);

        $projection = JmhzAttributeProjection::fromXml($this->submission('agreement'));

        self::assertCount(1, $projection->forms());
        $form = $projection->forms()[0];
        self::assertFalse($form->has('10240'));
        $this->assertSubmissionHeader($projection);
        $this->assertRegularZeroTable($form, [...self::FUND_ATTRIBUTES, ...self::AGREEMENT_SOCIAL_ATTRIBUTES]);
        // Pojistné a jeho základ jsou podle datových scénářů MH povinné i u vztahu
        // bez účasti na pojištění – vykazují se nulou (oprava fix/jmhz-dpp-pojistne-nula).
        foreach (self::AGREEMENT_SOCIAL_ATTRIBUTES as $attributeId) {
            self::assertSame('0', $form->value($attributeId), "Atribut {$attributeId} u dohody bez účasti.");
        }
    }

    private function assertSubmissionHeader(JmhzAttributeProjection $projection): void
    {
        $header = $projection->submission();
        self::assertSame('R', $header->value('10007'));
        self::assertSame('7', $header->value('10010'));
        self::assertSame('2026', $header->value('10011'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', (string) $header->value('10005'));
    }

    /**
     * Každý atribut tabulky „řádný formulář s nulovými hodnotami“ proti
     * hodnotě v sestaveném XML.
     *
     * @param list<string> $skipped atributy s doloženou odchylkou
     */
    private function assertRegularZeroTable(JmhzAttributeScope $form, array $skipped): void
    {
        $hasCode = $form->has('10240');
        $checked = 0;
        foreach (JmhzZeroReportProfile::table(JmhzZeroReportProfile::SITUATION_REGULAR_WITHOUT_WORK) as $attributeId => $rule) {
            $attributeId = (string) $attributeId;
            if (in_array($attributeId, $skipped, true)) {
                continue;
            }
            $label = "Atribut {$attributeId} ({$rule})";
            $value = $form->value($attributeId);
            switch ($rule) {
                case JmhzZeroReportProfile::RULE_ZERO:
                case JmhzZeroReportProfile::RULE_ZERO_OR_ACTUAL_WHEN_CONCURRENT:
                    self::assertNotNull($value, $label . ' chybí.');
                    self::assertSame(0.0, (float) $value, $label . ' není nula.');
                    break;
                case JmhzZeroReportProfile::RULE_FALSE:
                    self::assertFalse($form->boolean($attributeId), $label . ' není NE.');
                    break;
                case JmhzZeroReportProfile::RULE_OMIT:
                    self::assertFalse($form->has($attributeId), $label . ' se nemá uvádět.');
                    break;
                case JmhzZeroReportProfile::RULE_ACTUAL:
                    // Roční zúčtování (10319, 10320) je podle Pokynů k vyplnění
                    // MH 1.4.14 povinné jen v hlášení za leden až březen.
                    if (in_array($attributeId, ['10319', '10320'], true)) {
                        self::assertFalse($form->has($attributeId), $label . ' mimo leden až březen.');
                        break;
                    }
                    self::assertNotNull($value, $label . ' chybí.');
                    self::assertNotSame('', trim($value), $label . ' je prázdný.');
                    break;
                case JmhzZeroReportProfile::RULE_FIRST_DAY_OF_MONTH:
                case JmhzZeroReportProfile::RULE_LAST_DAY_OF_MONTH:
                    $expected = $rule === JmhzZeroReportProfile::RULE_FIRST_DAY_OF_MONTH
                        ? self::PERIOD_START
                        : self::PERIOD_END;
                    // Platnost kódu ELDP (10241, 10242) je NSP: „pokud je“.
                    if (in_array($attributeId, ['10241', '10242'], true) && !$hasCode) {
                        self::assertFalse($form->has($attributeId), $label . ' bez kódu ELDP.');
                        break;
                    }
                    self::assertSame($expected, $value, $label);
                    break;
                case JmhzZeroReportProfile::RULE_WITH_ELDP_CODE:
                    self::assertSame($hasCode, $form->has($attributeId), $label);
                    break;
                case JmhzZeroReportProfile::RULE_ZERO_WITH_ELDP_CODE:
                    if ($hasCode) {
                        self::assertSame('0', $value, $label . ' s kódem ELDP.');
                    } else {
                        self::assertFalse($form->has($attributeId), $label . ' bez kódu ELDP.');
                    }
                    break;
                default:
                    self::fail("{$label}: pravidlo nemá v testu predikát.");
            }
            ++$checked;
        }
        self::assertSame(37 - count($skipped), $checked);
    }

    /** @return array{employee_id:int,employment_id:int,name:string,sequence:int} */
    private function hireEmployee(string $name, string $sex, string $birthDate): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'hpp',
            'employment',
            40,
            10_000,
            true,
            '2026-01-01',
            healthTopUpResponsibility: 'employer_obstacle_verified',
        );
        $this->completeIdentity($person, $name, $sex, $birthDate, $sequence);
        $this->publishShifts($person['employment_id'], self::workdays(self::PERIOD));

        return $person + ['sequence' => $sequence];
    }

    /** @return array{employee_id:int,employment_id:int,name:string,sequence:int} */
    private function hireAgreement(string $name, string $sex, string $birthDate): array
    {
        $sequence = ++$this->sequence;
        $person = $this->createEmployment(
            $this->officeId,
            $name,
            $sequence,
            'dpp',
            'dpp',
            10,
            2_500,
            false,
            self::PERIOD_START,
        );
        $this->completeIdentity($person, $name, $sex, $birthDate, $sequence);

        return $person + ['sequence' => $sequence];
    }

    /** @param array{employee_id:int,employment_id:int,name:string} $person */
    private function completeIdentity(array $person, string $name, string $sex, string $birthDate, int $sequence): void
    {
        [$firstName, $lastName] = explode(' ', $name, 2);
        $this->completeJmhzEmployment($person, null, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'birth_date' => $birthDate,
            'sex' => $sex,
            'birth_number' => self::syntheticBirthNumber($birthDate, $sex, $sequence),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic($sequence), sprintf('4%020d', $sequence));
    }

    private function submission(string $scenario): string
    {
        $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, "zero-profile-{$scenario}");
        self::assertSame([], $run['blockers'], 'Zaseknutí: výpočet mzdy. ' . CanonicalJson::encode($run['blockers']));
        self::assertSame([], $run['warnings'], 'Zaseknutí: varování běhu. ' . CanonicalJson::encode($run['warnings']));
        self::assertNotNull($run['approved']);
        $revisionId = (int) $run['approved']->revision['id'];

        $preparation = $this->prepareJmhz($revisionId, "zero-profile-{$scenario}");
        self::assertSame(201, $preparation['status'], 'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']));
        self::assertSame(
            'source_ready',
            $preparation['body']['readiness_status'],
            'Zaseknutí: příprava. ' . CanonicalJson::encode($preparation['body']['issues'] ?? []),
        );
        $tested = $this->dryRunJmhz((int) $preparation['body']['id'], $this->officeId);
        self::assertSame(200, $tested['status'], CanonicalJson::encode($tested['body']));
        self::assertSame(
            'dry_run_valid',
            $tested['body']['status'],
            'Zaseknutí: XSD nebo kontroly. ' . CanonicalJson::encode($tested['body']['controls'] ?? $tested['body']),
        );

        return (string) $tested['body']['xml'];
    }
}
