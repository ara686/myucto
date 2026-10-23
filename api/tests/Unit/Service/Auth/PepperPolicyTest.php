<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Service\Auth\PepperPolicy;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\System\DiagnosticsConfigAllowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Produkční guard i HKDF fallback musí odmítnout pepper ze vzorové konfigurace,
 * který diagnostika už dřív hlásila jako nález.
 */
final class PepperPolicyTest extends TestCase
{
    #[DataProvider('rejectedPeppers')]
    public function testProductionRejectsEmptyPlaceholderAndShortPepper(string $pepper): void
    {
        self::assertNotNull(PepperPolicy::productionProblem($pepper));
    }

    /** @return array<string,array{string}> */
    public static function rejectedPeppers(): array
    {
        return [
            'prázdný' => [''],
            'mezery' => ['   '],
            'vzorový' => ['CHANGE-ME'],
            'vzorový malými' => ['change-me'],
            'bez pomlčky' => ['CHANGEME'],
            'krátký' => ['short-pepper-15'],
        ];
    }

    public function testGeneratedPepperIsAccepted(): void
    {
        self::assertNull(PepperPolicy::productionProblem(base64_encode(random_bytes(32))));
        self::assertNull(PepperPolicy::productionProblem(str_repeat('x', PepperPolicy::MIN_LENGTH)));
    }

    public function testHkdfFallbackRefusesPlaceholderPepper(): void
    {
        $encryption = new SecretEncryption(new Config(['app' => [
            'secret_encryption_key' => '',
            'pepper' => 'CHANGE-ME',
        ]]));

        self::assertNotNull($encryption->validateKey());
        $this->expectException(\RuntimeException::class);
        $encryption->encrypt('synthetic-secret');
    }

    public function testHkdfFallbackStillWorksWithRealPepper(): void
    {
        $encryption = new SecretEncryption(new Config(['app' => [
            'secret_encryption_key' => '',
            'pepper' => 'synthetic-pepper-for-unit-test-only',
        ]]));

        self::assertSame('synthetic-secret', $encryption->decrypt($encryption->encrypt('synthetic-secret')));
    }

    public function testDiagnosticsReportsPlaceholderWithTheSameRule(): void
    {
        $method = new \ReflectionMethod(DiagnosticsConfigAllowlist::class, 'describePresence');

        self::assertSame('<default:CHANGE-ME>', $method->invoke(null, 'change-me'));
        self::assertSame('<set>', $method->invoke(null, 'synthetic-pepper-for-unit-test-only'));
    }
}
