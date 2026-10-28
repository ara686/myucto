<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission;

use MyInvoice\Service\Payroll\Ruleset\CanonicalJson;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzControlSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzFrozenSubmissionIdentity;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzOrdinaryEvidenceCompatibility;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzScenarioRequirementSourceCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecPackageCatalog;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzXmlException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Volba verze JMHZ 2026/2027 ({@see JmhzSpecVersion}) a brány, které verzi
 * 2027 drží zavřenou, dokud není připnutý její balík specifikace.
 *
 * Rozhoduje vykazované období (měsíční hlášení) a datum události (REGZEC),
 * nikdy den odeslání: proto žádný test nepotřebuje hodiny.
 */
final class JmhzSpecVersionTest extends TestCase
{
    private const GUID = '0190A0B0-C0D0-7E0F-8A0B-0C0D0E0F1A2B';
    private const VARIABLE_SYMBOL = '9990000001';

    /** @return iterable<string,array{int,int,JmhzSpecVersion}> */
    public static function periods(): iterable
    {
        yield 'leden 2026' => [2026, 1, JmhzSpecVersion::V2026];
        yield 'listopad 2026 (oprava podaná v březnu 2027)' => [2026, 11, JmhzSpecVersion::V2026];
        yield 'prosinec 2026' => [2026, 12, JmhzSpecVersion::V2026];
        yield 'leden 2027' => [2027, 1, JmhzSpecVersion::V2027];
        yield 'prosinec 2028' => [2028, 12, JmhzSpecVersion::V2027];
    }

    #[DataProvider('periods')]
    public function testMonthlyReportVersionFollowsTheReportedPeriod(int $year, int $month, JmhzSpecVersion $expected): void
    {
        self::assertSame($expected, JmhzSpecVersion::forPeriod($year, $month));
        self::assertSame($expected, JmhzSpecVersion::forPeriodStart(sprintf('%04d-%02d-01', $year, $month)));
        self::assertSame($expected, JmhzSpecVersion::forPeriodStart(sprintf('%04d-%02d', $year, $month)));
    }

    public function testRegistrationVersionFollowsTheEventDate(): void
    {
        self::assertSame(JmhzSpecVersion::V2026, JmhzSpecVersion::forRegistrationEventDate('2026-12-31'));
        self::assertSame(JmhzSpecVersion::V2027, JmhzSpecVersion::forRegistrationEventDate('2027-01-01'));
    }

    /** @return iterable<string,array{\Closure():mixed,string}> */
    public static function invalidInputs(): iterable
    {
        yield 'měsíc 13' => [static fn () => JmhzSpecVersion::forPeriod(2026, 13), 'jmhz_spec_version_period_invalid'];
        yield 'neexistující den' => [static fn () => JmhzSpecVersion::forPeriodStart('2027-02-30'), 'jmhz_spec_version_period_invalid'];
        yield 'datum události' => [static fn () => JmhzSpecVersion::forRegistrationEventDate('2027-1-1'), 'jmhz_spec_version_period_invalid'];
        yield 'neznámá verze' => [static fn () => JmhzSpecVersion::fromStored('2028'), 'jmhz_spec_version_unknown'];
        yield 'prázdná verze' => [static fn () => JmhzSpecVersion::fromStored(''), 'jmhz_spec_version_unknown'];
    }

    #[DataProvider('invalidInputs')]
    public function testUnknownOrInvalidInputFailsClosed(\Closure $call, string $code): void
    {
        try {
            $call();
            self::fail('Neplatný vstup nesmí vybrat žádnou verzi.');
        } catch (JmhzXmlException $exception) {
            self::assertSame($code, $exception->validationCode);
        }
    }

    public function testStoredVersionRoundTrips(): void
    {
        self::assertSame(JmhzSpecVersion::V2026, JmhzSpecVersion::fromStored('2026'));
        self::assertSame(JmhzSpecVersion::V2027, JmhzSpecVersion::fromStored(' 2027 '));
    }

    public function testEnvelopeTypesPerVersionAndUndocumentedPrezec27(): void
    {
        self::assertSame('JMHZ25', JmhzSpecVersion::V2026->envelopeTypeFor('CSSZ_JMHZ'));
        self::assertSame('REGZEC25', JmhzSpecVersion::V2026->envelopeTypeFor('CSSZ_REGZEC'));
        self::assertSame('PREZEC26', JmhzSpecVersion::V2026->envelopeTypeFor('CSSZ_PREZEC'));
        self::assertSame('JMHZ27', JmhzSpecVersion::V2027->envelopeTypeFor('CSSZ_JMHZ'));
        self::assertSame('REGZEC27', JmhzSpecVersion::V2027->envelopeTypeFor('CSSZ_REGZEC'));
        self::assertNull(JmhzSpecVersion::V2027->envelopeTypeFor('CSSZ_PREZEC'));
        self::assertSame(JmhzSpecVersion::V2027, JmhzSpecVersion::forEnvelopeType('JMHZ27'));
        self::assertSame(JmhzSpecVersion::V2026, JmhzSpecVersion::forEnvelopeType('JMHZ25'));
        self::assertNull(JmhzSpecVersion::forEnvelopeType('NEMPRI25'));
    }

    public function testVersionedEnvelopeCodeMapsToTheSameAgenda(): void
    {
        self::assertSame('JMHZ25', JmhzSpecVersion::canonicalAgendaCode('JMHZ27'));
        self::assertSame('REGZEC25', JmhzSpecVersion::canonicalAgendaCode('regzec27'));
        self::assertSame('JMHZ25', JmhzSpecVersion::canonicalAgendaCode('JMHZ25'));
        self::assertSame('ELDP', JmhzSpecVersion::canonicalAgendaCode('ELDP'));
        self::assertTrue(JmhzSpecVersion::isEnvelopeOnlyCode('JMHZ27'));
        self::assertFalse(JmhzSpecVersion::isEnvelopeOnlyCode('JMHZ25'));
        self::assertTrue(JmhzSpecVersion::isMonthlyAgenda('JMHZ25'));
        self::assertTrue(JmhzSpecVersion::isMonthlyAgenda('JMHZ27'));
        self::assertFalse(JmhzSpecVersion::isMonthlyAgenda('JMHZ'));
    }

    public function testVersion2026StaysBuildable(): void
    {
        JmhzSpecPackageCatalog::requireBuildable(JmhzSpecVersion::V2026);
        $pin = (new JmhzSpecPackageCatalog())->requirePin(JmhzSpecVersion::V2026);
        self::assertSame(JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY, $pin['package_key']);
        self::assertSame(JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256, $pin['manifest_sha256']);
        $entry = (new JmhzSchemaCatalog())->entryPoint();
        self::assertSame(JmhzSchemaCatalog::PACKAGE_KEY, $entry['package_key']);
        self::assertSame(JmhzSchemaCatalog::NS_PODANI, JmhzSchemaCatalog::podaniNamespaceFor(JmhzSpecVersion::V2026));
    }

    /**
     * Hlášení za 01/2027 se nesestaví, dokud integrátor nepřipne balík 2027
     * (WP-1). Blokace je doménová výjimka s kódem pro obrazovku, ne chyba 500.
     */
    public function testVersion2027IsBlockedUntilItsPackageIsPinned(): void
    {
        self::assertNull((new JmhzSpecPackageCatalog())->pinFor(JmhzSpecVersion::V2027));
        foreach ([
            static fn () => JmhzSpecPackageCatalog::requireBuildable(JmhzSpecVersion::forPeriod(2027, 1)),
            static fn () => (new JmhzSpecPackageCatalog())->load(JmhzSpecPackageCatalog::PACKAGE_KEY_2027),
            static fn () => (new JmhzSchemaCatalog())->entryPointFor(JmhzSpecVersion::V2027),
        ] as $index => $call) {
            try {
                $call();
                self::fail("Verze 2027 prošla bránou č. {$index}.");
            } catch (JmhzXmlException $exception) {
                self::assertSame(JmhzSpecPackageCatalog::UNAVAILABLE_CODE, $exception->validationCode);
                self::assertStringContainsString('2027', $exception->getMessage());
            }
        }
    }

    /** Stub manifest: jakmile je balík 2027 připnutý, katalog ho načte stejnou cestou jako 2026. */
    public function testPinnedVersion2027LoadsThroughTheSameCatalog(): void
    {
        $root = sys_get_temp_dir() . '/jmhz-spec-2027-' . bin2hex(random_bytes(4));
        mkdir($root . '/dictionary-1.5.0.2', 0777, true);
        try {
            $payload = [
                'package_key' => JmhzSpecPackageCatalog::PACKAGE_KEY_2027,
                'sources' => [],
                'dictionary_attributes' => [],
                'codebooks' => [],
                'counts' => [
                    'attributes' => 0,
                    'reporting_marker_attributes' => 0,
                    'reporting_marker_xsd_mapped_attributes' => 0,
                    'monthly_attributes' => 0,
                    'monthly_xsd_mapped_attributes' => 0,
                    'codebooks' => 0,
                    'embedded_codebooks' => 0,
                    'external_reference_codebooks' => 0,
                    'codebook_entries' => 0,
                ],
            ];
            $hash = hash('sha256', CanonicalJson::encode($payload));
            file_put_contents(
                $root . '/dictionary-1.5.0.2/manifest.json',
                json_encode(['manifest_sha256' => $hash, 'payload' => $payload], JSON_THROW_ON_ERROR),
            );
            $catalog = new JmhzSpecPackageCatalog($root, [
                '2027' => ['package_key' => JmhzSpecPackageCatalog::PACKAGE_KEY_2027, 'manifest_sha256' => $hash],
            ]);

            self::assertSame($hash, $catalog->requirePin(JmhzSpecVersion::V2027)['manifest_sha256']);
            self::assertSame($hash, $catalog->load(JmhzSpecPackageCatalog::PACKAGE_KEY_2027)['manifest_sha256']);

            $wrong = new JmhzSpecPackageCatalog($root, [
                '2027' => ['package_key' => JmhzSpecPackageCatalog::PACKAGE_KEY_2027, 'manifest_sha256' => str_repeat('0', 64)],
            ]);
            $this->expectException(\UnexpectedValueException::class);
            $wrong->load(JmhzSpecPackageCatalog::PACKAGE_KEY_2027);
        } finally {
            @unlink($root . '/dictionary-1.5.0.2/manifest.json');
            @rmdir($root . '/dictionary-1.5.0.2');
            @rmdir($root);
        }
    }

    public function testPackageKeysCarryTheirVersion(): void
    {
        self::assertSame(JmhzSpecVersion::V2026, JmhzSpecPackageCatalog::versionOf(JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY));
        // Historické zmrazené podání nad 1.4.3.4 patří dál k verzi 2026.
        self::assertSame(
            JmhzSpecVersion::V2026,
            JmhzSpecPackageCatalog::versionOf('jmhz-xsd-1.4.3.4_dictionary-1.4.1.6_controls-source-1.4.2.8_manifest-v1'),
        );
        self::assertSame(JmhzSpecVersion::V2027, JmhzSpecPackageCatalog::versionOf(JmhzSpecPackageCatalog::PACKAGE_KEY_2027));
        self::assertNull(JmhzSpecPackageCatalog::versionOf('jmhz-xsd-9.9_unknown'));
    }

    public function testOrdinaryEvidenceIsAcceptedOnlyForItsOwnVersion(): void
    {
        $current = [
            'package_key' => JmhzSpecPackageCatalog::DEFAULT_PACKAGE_KEY,
            'spec_manifest_sha256' => JmhzSpecPackageCatalog::DEFAULT_MANIFEST_SHA256,
            'scenario_catalog_key' => JmhzScenarioRequirementSourceCatalog::CATALOG_KEY,
            'scenario_manifest_sha256' => JmhzScenarioRequirementSourceCatalog::MANIFEST_SHA256,
            'control_catalog_key' => JmhzControlSourceCatalog::CATALOG_KEY,
            'control_manifest_sha256' => JmhzControlSourceCatalog::MANIFEST_SHA256,
        ];
        self::assertSame(JmhzSpecVersion::V2026, JmhzOrdinaryEvidenceCompatibility::specVersionOf($current));
        self::assertTrue(JmhzOrdinaryEvidenceCompatibility::acceptsSpecificationFor($current, JmhzSpecVersion::V2026));
        self::assertFalse(JmhzOrdinaryEvidenceCompatibility::acceptsSpecificationFor($current, JmhzSpecVersion::V2027));

        $future = ['package_key' => JmhzSpecPackageCatalog::PACKAGE_KEY_2027] + $current;
        self::assertSame(JmhzSpecVersion::V2027, JmhzOrdinaryEvidenceCompatibility::specVersionOf($future));
        self::assertFalse(JmhzOrdinaryEvidenceCompatibility::acceptsSpecificationFor($future, JmhzSpecVersion::V2026));
        self::assertFalse(JmhzOrdinaryEvidenceCompatibility::acceptsSpecificationFor($future, JmhzSpecVersion::V2027));
        self::assertNull(JmhzOrdinaryEvidenceCompatibility::specVersionOf(['package_key' => 'neznamy']));
    }

    public function testCorrectionFor2026FrozenIn2027CarriesVersion2026(): void
    {
        $identity = JmhzFrozenSubmissionIdentity::read(self::frozen(JmhzSchemaCatalog::NS_PODANI, 2026, 11, 'O'));

        self::assertSame(JmhzSpecVersion::V2026, $identity->specVersion);
        self::assertSame('O', $identity->submissionType);
        JmhzSpecPackageCatalog::requireBuildable($identity->specVersion);
    }

    /** Datová věta 2026 za období 2027 (a naopak) se nesmí přečíst jako platné podání. */
    public function testFrozenPayloadInTheWrongYearIsRefused(): void
    {
        foreach ([
            [JmhzSchemaCatalog::NS_PODANI, 2027, 1],
            [JmhzSchemaCatalog::NS_PODANI_2027, 2026, 12],
        ] as [$namespace, $year, $month]) {
            try {
                JmhzFrozenSubmissionIdentity::read(self::frozen($namespace, $year, $month, 'R'));
                self::fail("Věta {$namespace} za {$month}/{$year} prošla.");
            } catch (JmhzXmlException $exception) {
                self::assertSame('jmhz_spec_version_mismatch', $exception->validationCode);
            }
        }
        $identity = JmhzFrozenSubmissionIdentity::read(self::frozen(JmhzSchemaCatalog::NS_PODANI_2027, 2027, 1, 'R'));
        self::assertSame(JmhzSpecVersion::V2027, $identity->specVersion);
    }

    public function testRegzecVersionFollowsTheEventDateAndFailsClosedFor2027(): void
    {
        $catalog = new PayrollRegistrationSchemaCatalog();
        self::assertSame('REGZEC25', $catalog->documentTypeFor('REGZEC25', '2026-12-31'));
        self::assertSame('PREZEC26', $catalog->documentTypeFor('PREZEC26', '2026-12-31'));

        foreach ([
            static fn () => $catalog->documentTypeFor('REGZEC25', '2027-01-01'),
            static fn () => $catalog->documentTypeFor('PREZEC26', '2027-01-01'),
            static fn () => $catalog->schemaFor('REGZEC27'),
        ] as $index => $call) {
            try {
                $call();
                self::fail("Registrace 2027 prošla bránou č. {$index}.");
            } catch (PayrollRegistrationXmlException $exception) {
                self::assertSame(PayrollRegistrationSchemaCatalog::UNAVAILABLE_CODE, $exception->validationCode);
            }
        }
        self::assertSame(PayrollRegistrationSchemaCatalog::NS_REGZEC_2026, $catalog->schemaFor('REGZEC25')['namespace']);
    }

    private static function frozen(string $namespace, int $year, int $month, string $type): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<jmhz xmlns="' . $namespace . '"><hlavicka>'
            . '<idPodani>' . self::GUID . '</idPodani>'
            . '<variabilniSymbol>' . self::VARIABLE_SYMBOL . '</variabilniSymbol>'
            . '<mesic>' . $month . '</mesic><rok>' . $year . '</rok>'
            . '<typPodani>' . $type . '</typPodani>'
            . '</hlavicka></jmhz>';
    }
}
