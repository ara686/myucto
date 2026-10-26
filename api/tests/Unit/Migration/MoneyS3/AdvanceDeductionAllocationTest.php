<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\MoneyS3;

use MyInvoice\Service\Migration\MoneyS3\AdvanceDeductionMatcher;
use PHPUnit\Framework\TestCase;

/** Párování čerpání záloh po partnerovi ({@see AdvanceDeductionMatcher::allocate()}). */
final class AdvanceDeductionAllocationTest extends TestCase
{
    public function testOpeningIsConsumedFirstThenOldestAdvancePaidBeforeDeduction(): void
    {
        $result = AdvanceDeductionMatcher::allocate(
            ['ico:1' => 1000.0],
            [self::advance(10, '2025-01-10', 1000.0), self::advance(11, '2025-02-10', 1000.0)],
            [self::deduction(20, '2025-01-05', 1000.0), self::deduction(21, '2025-02-05', 1000.0), self::deduction(22, '2025-03-05', 1000.0)],
        );
        self::assertSame([21 => [10 => 1000.0], 22 => [11 => 1000.0]], $result['deductions']);
        self::assertSame(1000.0, $result['opening_consumed']);
        self::assertSame([], $result['unmatched']);
    }

    public function testDeductionNeverTakesAdvancePaidLaterNorMoreThanOpen(): void
    {
        $result = AdvanceDeductionMatcher::allocate(
            [],
            [self::advance(10, '2025-03-01', 500.0), self::advance(11, '2025-01-01', 300.0)],
            [self::deduction(20, '2025-02-01', 1000.0), self::deduction(21, '2025-04-01', 400.0)],
        );
        self::assertSame([20 => [11 => 300.0], 21 => [10 => 400.0]], $result['deductions']);
        self::assertSame([20 => 700.0], $result['unmatched']);
    }

    public function testReferenceWinsAndPartnersStaySeparate(): void
    {
        $result = AdvanceDeductionMatcher::allocate(
            ['ico:2' => 5000.0],
            [self::advance(10, '2025-01-01', 1000.0), self::advance(11, '2025-02-01', 1000.0), self::advance(12, '2025-01-01', 900.0, 'ico:2')],
            [self::deduction(20, '2025-03-01', 600.0, [11]), self::deduction(21, '2025-03-02', 1500.0)],
        );
        self::assertSame([20 => [11 => 600.0], 21 => [10 => 1000.0, 11 => 400.0]], $result['deductions']);
        self::assertSame(0.0, $result['opening_consumed'], 'Počáteční stav jiného partnera se nečerpá.');
    }

    public function testRoundingCentsAreNotChainedToNextAdvance(): void
    {
        $result = AdvanceDeductionMatcher::allocate(
            [],
            [self::advance(10, '2025-01-10', 33950.41), self::advance(11, '2025-02-10', 33950.42)],
            [self::deduction(20, '2025-02-05', 33950.42), self::deduction(21, '2025-03-05', 33950.41)],
        );
        self::assertSame([20 => [10 => 33950.41], 21 => [11 => 33950.41]], $result['deductions']);
        self::assertSame([20 => 0.01], $result['unmatched']);
    }

    /** @return array{id:int,partner:string,date:string,open:float} */
    private static function advance(int $id, string $date, float $open, string $partner = 'ico:1'): array
    {
        return ['id' => $id, 'partner' => $partner, 'date' => $date, 'open' => $open];
    }

    /**
     * @param list<int> $refs
     * @return array{id:int,partner:string,date:string,amount:float,refs:list<int>}
     */
    private static function deduction(int $id, string $date, float $amount, array $refs = [], string $partner = 'ico:1'): array
    {
        return ['id' => $id, 'partner' => $partner, 'date' => $date, 'amount' => $amount, 'refs' => $refs];
    }
}
