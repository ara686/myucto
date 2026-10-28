<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Submission\Eldp\EldpPensionAgeCode;
use PHPUnit\Framework\TestCase;

/** Jediné pravidlo kódu ELDP „D" pro roční evidenční list i měsíční hlášení JMHZ. */
final class EldpPensionAgeCodeTest extends TestCase
{
    public function testCodeStartsOnTheEarlierOfAgeAndEarlyPension(): void
    {
        self::assertNull(EldpPensionAgeCode::codeFrom([]));
        self::assertNull(EldpPensionAgeCode::codeFrom(['pension_age_reached_on' => null, 'early_pension_from' => null]));
        self::assertSame('2025-03-15', EldpPensionAgeCode::codeFrom(['pension_age_reached_on' => '2025-03-15', 'early_pension_from' => null]));
        self::assertSame('2024-11-01', EldpPensionAgeCode::codeFrom(['pension_age_reached_on' => '2025-03-15', 'early_pension_from' => '2024-11-01']));
    }

    public function testPlacementInsideAnInterval(): void
    {
        self::assertSame(EldpPensionAgeCode::PLAIN, EldpPensionAgeCode::placement(null, '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::PLAIN, EldpPensionAgeCode::placement('2026-08-01', '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::MID_INTERVAL, EldpPensionAgeCode::placement('2026-07-31', '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::PENSION_AGE, EldpPensionAgeCode::placement('2026-07-01', '2026-07-01', '2026-07-31'));
        self::assertSame(EldpPensionAgeCode::PENSION_AGE, EldpPensionAgeCode::placement('2025-01-01', '2026-07-01', '2026-07-31'));
    }

    /**
     * Odečtené doby jen po dovršení věku bez pobírání starobního důchodu;
     * předčasný důchod kód D zakládá, odečtené doby ne.
     */
    public function testDeductedPeriodsOnlyAfterPensionAgeWithoutPension(): void
    {
        $pension = static fn (?string $age, ?string $early = null, ?string $full = null): array => [
            'pension_age_reached_on' => $age,
            'early_pension_from' => $early,
            'full_pension_paid_from' => $full,
        ];

        self::assertTrue(EldpPensionAgeCode::deductsPeriods($pension('2025-04-01'), '2025-05-01', '2025-05-31'));
        self::assertTrue(EldpPensionAgeCode::deductsPeriods($pension('2025-05-01'), '2025-05-01', '2025-05-31'));
        self::assertFalse(EldpPensionAgeCode::deductsPeriods($pension('2025-05-02'), '2025-05-01', '2025-05-31'));
        self::assertFalse(EldpPensionAgeCode::deductsPeriods($pension(null, '2024-09-01'), '2025-05-01', '2025-05-31'));
        self::assertFalse(EldpPensionAgeCode::deductsPeriods($pension('2025-04-01', '2023-01-01'), '2025-05-01', '2025-05-31'));
        self::assertTrue(EldpPensionAgeCode::deductsPeriods($pension('2025-04-01', '2025-06-01'), '2025-05-01', '2025-05-31'));
        self::assertFalse(EldpPensionAgeCode::deductsPeriods($pension('2025-04-01', null, '2025-05'), '2025-05-01', '2025-05-31'));
        self::assertTrue(EldpPensionAgeCode::deductsPeriods($pension('2025-04-01', null, '2025-06'), '2025-05-01', '2025-05-31'));
    }

    public function testSecondPositionBecomesD(): void
    {
        self::assertSame('1D+', EldpPensionAgeCode::withPensionAge('1++'));
        self::assertSame('AD+', EldpPensionAgeCode::withPensionAge('A++'));
        self::assertSame('DD+', EldpPensionAgeCode::withPensionAge('D++'));
        self::assertSame('1DT', EldpPensionAgeCode::withPensionAge('1+T'));
    }
}
