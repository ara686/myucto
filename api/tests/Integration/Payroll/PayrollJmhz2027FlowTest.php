<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Payroll;

use MyInvoice\Repository\Payroll\PayrollComponentJmhzMappingRepository;
use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Ruleset\PayrollRulesetException;
use MyInvoice\Tests\Support\PayrollFullFlowTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Mzdový běh → normalizovaný dokument JMHZ za 01/2027.
 *
 * Daňové a pojistné hodnoty dokumentu 2027 smí vzniknout jen z mzdových
 * pravidel 2027. Dokud legislativní sada 2027 v aplikaci není, lednový běh
 * se nespočítá, příprava hlášení nevznikne a dokument 2027 tak nikdy
 * neponese částky spočtené pravidly 2026.
 */
#[Group('integration')]
#[Group('payroll-full-flow')]
final class PayrollJmhz2027FlowTest extends TestCase
{
    use PayrollFullFlowTrait;

    private const PERIOD = '2027-01';
    private const PERIOD_START = '2027-01-01';
    private const PAYDAY = '2027-02-12';

    private int $officeId;
    private int $baseComponentId;

    protected function setUp(): void
    {
        $this->bootPayrollFullFlow();
        $this->officeId = $this->createOffice('JMHZ27', 'Syntetická registrace JMHZ 2027', '1100004322');
        $this->configureSocialInsuranceOutput($this->officeId);
        $this->configureHealthInsuranceOutput();
        $this->baseComponentId = $this->createComponent('MZDA_2027_FLOW', 'base_wage', 'regular');
        $mappings = $this->container->get(PayrollComponentJmhzMappingRepository::class);
        self::assertInstanceOf(PayrollComponentJmhzMappingRepository::class, $mappings);
        $mappings->put($this->supplierId, $this->baseComponentId, '10329', null, $this->actors[0]);
    }

    protected function tearDown(): void
    {
        $this->tearDownPayrollFullFlow();
    }

    public function testJanuary2027RunIsBlockedUntilRuleset2027Exists(): void
    {
        $person = $this->createEmployment(
            $this->officeId,
            'Jana Lednová',
            1,
            'dpp',
            'dpp',
            10,
            2_500,
            false,
            self::PERIOD_START,
        );
        $this->completeJmhzEmployment($person, identity: [
            'first_name' => 'Jana',
            'last_name' => 'Lednová',
            'birth_date' => '1990-05-05',
            'sex' => 'female',
            'birth_number' => self::syntheticBirthNumber('1990-05-05', 'female', 1),
        ]);
        $this->assignJmhzIdentity($person, self::syntheticOic(1), sprintf('3%020d', 1));
        $this->createApprovedInput($person, $this->baseComponentId, 500_000, 'jmhz27-1', self::PERIOD_START);

        // Sada 2027 zatím nepokrývá všechny domény (exekuční srážky, lhůty,
        // číselníky). Běh se proto buď nespočítá vůbec, nebo skončí neschválený
        // s blokátorem – nikdy se neschválí s čísly roku 2026.
        try {
            $run = $this->runPayrollMonth(self::PERIOD_START, self::PAYDAY, $this->officeId, 'jmhz27');
            self::assertNull($run['approved'], 'Lednový běh 2027 se nesmí schválit: ' . CanonicalJson::encode([
                'blockers' => $run['blockers'],
            ]));
            self::assertNotSame([], $run['blockers']);
        } catch (PayrollRulesetException $exception) {
            self::assertStringContainsString('2027-01-01', $exception->getMessage());
        }

        self::assertSame(
            0,
            (int) $this->scalar(
                "SELECT COUNT(*) FROM payroll_runs WHERE supplier_id = ? AND period_start = ? AND status = 'approved'",
                [$this->supplierId, self::PERIOD_START],
            ),
        );
    }
}
