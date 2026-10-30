<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Migration\Pohoda;

use MyInvoice\Service\Migration\Pohoda\BalanceItemImporter;
use PHPUnit\Framework\TestCase;

final class BalanceItemImporterTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function line(string $side, int $cents, string $date, string $account = '315.999'): array
    {
        return ['side' => $side, 'cents' => $cents, 'entry_date' => $date, 'account_code' => $account];
    }

    public function testReturnedPaymentCancelsAcrossPartners(): void
    {
        $rest = BalanceItemImporter::withoutReturns([
            self::line('debit', 1331000, '2026-03-06'),
            self::line('credit', 1331000, '2026-03-10'),
            self::line('debit', 3139500, '2026-08-28'),
        ]);
        self::assertSame([3139500], array_column($rest, 'cents'));
    }

    public function testDistantOrOtherAccountAmountStaysOpen(): void
    {
        $lines = [
            self::line('debit', 50000, '2026-01-01'),
            self::line('credit', 50000, '2026-04-01'),
            self::line('debit', 70000, '2026-05-01'),
            self::line('credit', 70000, '2026-05-02', '325.999'),
        ];
        self::assertCount(4, BalanceItemImporter::withoutReturns($lines));
    }

    public function testNearestReturnWins(): void
    {
        $rest = BalanceItemImporter::withoutReturns([
            self::line('debit', 10000, '2026-02-20'),
            self::line('credit', 10000, '2026-02-01'),
            self::line('credit', 10000, '2026-02-21'),
        ]);
        self::assertSame(['2026-02-01'], array_column($rest, 'entry_date'));
    }

    public function testItemMustNarrowWithoutCrossingDifference(): void
    {
        self::assertTrue(BalanceItemImporter::narrows(3339500, 3339500), 'Přesně rozdíl.');
        self::assertTrue(BalanceItemImporter::narrows(3339500, 200000), 'Část rozdílu.');
        self::assertFalse(BalanceItemImporter::narrows(950851, 1802503), 'Položka 18 025 Kč na rozdíl 9 509 Kč jen přesune nesoulad.');
        self::assertFalse(BalanceItemImporter::narrows(0, 712100), 'Účet bez rozdílu položku nedostane.');
        self::assertFalse(BalanceItemImporter::narrows(492762, -100), 'Opačný směr rozdíl zvětší.');
    }
}
