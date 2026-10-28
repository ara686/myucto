<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Payroll\Submission\Registration;

use DOMDocument;
use DOMXPath;
use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;
use MyInvoice\Service\Payroll\Submission\Jmhz\Transport\JmhzGovTalkRequestShape;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1Snapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationA1SnapshotBuilder;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationIdentitySnapshot;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationInteraction;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationSchemaCatalog;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlException;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlPayload;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlSerializer;
use MyInvoice\Service\Payroll\Submission\Registration\PayrollRegistrationXmlValidator;
use PHPUnit\Framework\TestCase;

/**
 * REGZEC27 (XSD 1.5, EDV 1.5.0.3) pro události s rozhodným datem od 1. 1. 2027.
 * Každá věta projde validátorem včetně připnutého XSD 1.5. Data jsou syntetická.
 */
final class PayrollRegistrationRegzec27Test extends TestCase
{
    private const NS = 'http://schemas.cssz.cz/REGZEC/2027';

    private const START = '2027-01-04';

    private const PREPARED_A1 = '2027-01-02';

    private const EFFECTIVE = '2027-01-10';

    private const PREPARED = '2027-01-12';

    public function testCzechA1Is2027FormAndPassesXsd15(): void
    {
        $xml = self::validated(self::a1Payload(self::a1(self::source()), self::identity()));
        $root = self::xpath($xml);

        self::assertSame(self::NS, $root->document->documentElement?->namespaceURI);
        self::assertSame('1.5', $root->evaluate('string(/r:REGZEC/@version)'));
        self::assertSame(self::START, $root->evaluate('string(//r:job/@fro)'));
        self::assertStringNotContainsString('REGZEC/2025', $xml);
    }

    /** EDV 1.5 (JMHZ-3428), ID 10568/10569: platnost pobytu u občana mimo EHP. */
    public function testForeignerOutsideEeaCarriesStayValidityAndPassesXsd(): void
    {
        $source = self::foreignSource('UA');
        $source['foreign_worker']['stay_valid_from'] = '2026-06-01';
        $source['foreign_worker']['stay_valid_to'] = '2028-05-31';
        $xml = self::validated(self::a1Payload(self::a1($source, 'UA'), self::identity('UA')));

        self::assertStringContainsString('stayvalidfro="2026-06-01"', $xml);
        self::assertStringContainsString('stayvalidto="2028-05-31"', $xml);
        self::assertStringContainsString('perm="22"', $xml);
    }

    public function testForeignerOutsideEeaWithoutStayValidityIsRejected(): void
    {
        $payload = self::a1Payload(self::a1(self::foreignSource('UA'), 'UA'), self::identity('UA'));

        self::assertCode('registration_regzec27_stay_validity_missing', $payload);
    }

    public function testEuCitizenNeedsNoStayValidityButMustHaveReasonOne(): void
    {
        $source = self::foreignSource('SK');
        $source['foreign_worker']['free_access_reason_code'] = '1';
        $xml = self::validated(self::a1Payload(self::a1($source, 'SK'), self::identity('SK')));
        self::assertStringNotContainsString('stayvalid', $xml);

        $source['foreign_worker']['free_access_reason_code'] = '3';
        self::assertCode(
            'registration_regzec27_eu_free_access',
            self::a1Payload(self::a1($source, 'SK'), self::identity('SK')),
        );
    }

    /**
     * Changelog 1.5 ř. 227 až 230: číslo orientační nejvýš 4 znaky u všech
     * adres. REGZEC25 dovoluje u zahraniční adresy 12 znaků.
     */
    public function testForeignOrientationNumberLongerThanFourCharactersIsRejectedFrom2027(): void
    {
        $source = self::foreignSource('SK');
        $source['foreign_worker']['free_access_reason_code'] = '1';
        $source['tax_residency']['residence_address']['orientation_number'] = '12345';

        $problems = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            $source,
            self::identity('SK'),
            ['supplier_id' => 11, 'employee_id' => 41, 'employment_id' => 51, 'effective_on' => self::START],
        );
        self::assertContains('tax_residency.residence_address.orientation_number', array_column($problems, 'field'));

        $source2026 = $source;
        $source2026['source']['effective_on'] = '2026-08-05';
        $source2026['employment']['actual_start_on'] = '2026-08-05';
        $source2026['employment']['contract_start_on'] = '2026-08-05';
        $source2026['foreign_worker']['free_access_reason_code'] = '1';
        $problems2026 = (new PayrollRegistrationA1SnapshotBuilder())->problems(
            $source2026,
            self::identity('SK'),
            ['supplier_id' => 11, 'employee_id' => 41, 'employment_id' => 51, 'effective_on' => '2026-08-05'],
        );
        self::assertNotContains('tax_residency.residence_address.orientation_number', array_column($problems2026, 'field'));

        $delta = [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['contact_address' => [
                'street' => 'Testovacia', 'house_number' => '7', 'orientation_number' => '12345',
                'postal_code' => '81101', 'city' => 'Testovo', 'country_code' => 'SK',
            ]],
        ];
        self::assertCode('registration_regzec27_orientation_number_too_long', self::eventPayload(3, $delta));
    }

    /** Changelog 1.5 ř. 217 až 219: kód adresního místa 1 až 9 číslic. */
    public function testShortRuianPointIsAcceptedAndOverlongRejected(): void
    {
        $source = self::source();
        $source['permanent_address']['ruian_point'] = '1234';
        self::validated(self::a1Payload(self::a1($source), self::identity()));

        $source['permanent_address']['ruian_point'] = '1234567890';
        self::assertCode(
            'registration_regzec27_ruian_point_invalid',
            self::a1Payload(self::a1($source), self::identity()),
        );
    }

    /** A2 (skončení od 1. 1. 2027): 10588 místo 10531 až 10533. */
    public function testA2WritesSeveranceBenefitInsteadOfGoldenHandshake(): void
    {
        $xml = self::validated(self::eventPayload(2, [
            'end_on' => self::EFFECTIVE,
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'ended_by_death' => false,
            'unemployment' => [
                'employment_type' => '1',
                'termination_reason' => '4',
                'entitlement' => 'A',
                'paid_in_full' => 'A',
                'average_net_earnings' => '30000',
                'golden_handshake' => '60000',
                'pension_periods' => [['from' => '2025-01-01', 'to' => self::EFFECTIVE]],
            ],
        ]));

        self::assertStringContainsString('severencebenefit="60000"', $xml);
        self::assertStringNotContainsString('goldenhandshake', $xml);
        self::assertStringContainsString('<job oid="200000000000000000002" to="' . self::EFFECTIVE . '"', $xml);
    }

    /** EDV 1.5 (JMHZ-3394): služební poměr podle z. 361/2003 Sb. a jeho důvody 7 až 14. */
    public function testA2ServiceTypeThreeAcceptsOnlyItsReasons(): void
    {
        $data = [
            'end_on' => self::EFFECTIVE,
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'ended_by_death' => false,
            'unemployment' => [
                'employment_type' => '3',
                'service_termination_reason' => '9',
                'average_net_earnings' => '30000',
                'pension_periods' => [['from' => '2025-01-01', 'to' => self::EFFECTIVE]],
            ],
        ];
        $xml = self::validated(self::eventPayload(2, $data));
        self::assertStringContainsString('typeempl="3"', $xml);
        self::assertStringContainsString('rsnterrel="9"', $xml);

        $data['unemployment']['service_termination_reason'] = '2';
        self::assertCode('registration_regzec27_service_termination_reason', self::eventPayload(2, $data));
    }

    public function testA3ChangePassesXsdAndCannotCarryForeignLegislation(): void
    {
        $data = [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => [
                'permanent_address' => [
                    'street' => 'Nová', 'house_number' => '5', 'orientation_number' => '12a',
                    'postal_code' => '11000', 'city' => 'Testov', 'country_code' => 'CZ',
                ],
            ],
        ];
        $xml = self::validated(self::eventPayload(3, $data));
        self::assertStringContainsString('fro="' . self::EFFECTIVE . '"', $xml);

        $data['delta']['foreign_legislation'] = ['applies' => true, 'country_code' => 'SK'];
        self::assertCode('registration_regzec27_a3_foreign_legislation_forbidden', self::eventPayload(3, $data));
    }

    /** 10009 <= datum vyplnění: změnu do budoucna ČSSZ nepřijme. */
    public function testA3ChangeInTheFutureIsRejected(): void
    {
        $payload = self::eventPayload(3, [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['health_insurance_code' => '201'],
        ], preparedOn: '2027-01-08');

        self::assertCode('registration_regzec27_change_in_future', $payload);
    }

    /** Kontrola 4: doklad totožnosti v A4 jen celý. */
    public function testA4CorrectionPassesXsdAndNeedsCompleteProofOfIdentity(): void
    {
        $data = [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['health_insurance_code' => '201'],
        ];
        self::validated(self::eventPayload(4, $data));

        $data['delta']['proof_identity'] = ['type_code' => 'P', 'number' => 'SYN1'];
        self::assertCode('registration_regzec27_proof_identity_incomplete', self::eventPayload(4, $data));
    }

    /** EDV 1.5.0.1 (JMHZ-2997): A7 nese povinně kód státu cizích předpisů 10428. */
    public function testA7CarriesForeignLegislationStateAndPassesXsd(): void
    {
        $xml = self::validated(self::eventPayload(7, [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'foreign_insurance' => [
                'current' => 'S',
                'name' => 'Syntetická instituce',
                'country_code' => 'SK',
                'identifier' => 'SYN-123',
            ],
        ]));

        self::assertStringContainsString('<forinreg state="SK"/>', $xml);
    }

    /** Hranice verzí (EDV 1.5.0.3, Slovník): ročník musí odpovídat rozhodnému datu. */
    public function testPayloadWithWrongVintageIsRejected(): void
    {
        $payload = self::eventPayload(3, [
            'activity_code' => '1',
            'relationship_detail_code' => '1',
            'birth_number' => '9152031234',
            'delta' => ['health_insurance_code' => '201'],
        ], version: JmhzSpecVersion::V2026);

        self::assertCode('registration_spec_version_mismatch', $payload);
    }

    public function testDecisiveDateFollowsEndDateForChangeAndEffectiveDateOtherwise(): void
    {
        self::assertSame('2026-12-31', PayrollRegistrationSchemaCatalog::decisiveDate(3, '2027-01-05', [
            'delta' => ['employment' => ['end_on' => '2026-12-31']],
        ]));
        self::assertSame('2027-01-05', PayrollRegistrationSchemaCatalog::decisiveDate(3, '2027-01-05', ['delta' => []]));
        self::assertSame('2027-01-05', PayrollRegistrationSchemaCatalog::decisiveDate(2, '2027-01-05', null));
    }

    /** Obálka: `eType` REGZEC27 z jmenného prostoru zmrazené věty, Class beze změny. */
    public function testEnvelopeTypeFollowsTheFrozenSentence(): void
    {
        $xml = self::validated(self::a1Payload(self::a1(self::source()), self::identity()));

        self::assertSame('REGZEC27', JmhzGovTalkRequestShape::forPayload('CSSZ_REGZEC', $xml)->bodyEnvelopeType);
    }

    private static function validated(PayrollRegistrationXmlPayload $payload): string
    {
        $xml = (new PayrollRegistrationXmlSerializer())->serialize($payload);
        (new PayrollRegistrationXmlValidator(new PayrollRegistrationSchemaCatalog()))->validate($payload, $xml);

        return $xml;
    }

    private static function assertCode(string $code, PayrollRegistrationXmlPayload $payload): void
    {
        try {
            self::validated($payload);
            self::fail("Očekávaná vada {$code} nenastala.");
        } catch (PayrollRegistrationXmlException $exception) {
            self::assertSame($code, $exception->validationCode, $exception->getMessage());
        }
    }

    private static function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('r', self::NS);

        return $xpath;
    }

    /** @return array<string,mixed> */
    private static function source(): array
    {
        $source = PayrollRegistrationA1SnapshotBuilderTest::source('1', '1');
        $source['source']['effective_on'] = self::START;
        $source['employment']['actual_start_on'] = self::START;
        $source['employment']['contract_start_on'] = self::START;

        return $source;
    }

    /** @return array<string,mixed> */
    private static function foreignSource(string $citizenship): array
    {
        $source = self::source();
        $source['employment']['expected_workplaces'] = 'Sídlo zaměstnavatele';
        $source['employment']['required_education_code'] = 'T';
        $source['tax_residency'] = [
            'country_code' => $citizenship,
            'identifier_type' => 'D',
            'identifier' => $citizenship . '123456789',
            'residence_address' => [
                'street' => 'Testovacia',
                'house_number' => '7',
                'orientation_number' => null,
                'city' => 'Testovo',
                'postal_code' => '81101',
                'country_code' => $citizenship,
                'ruian_point' => null,
            ],
        ];
        $source['proof_identity'] = [
            'type_code' => 'P',
            'number' => 'SYN000001',
            'foreign_issuer' => 'Municipal office, Testov',
            'country_code' => $citizenship,
        ];
        $source['foreign_worker'] = [
            'free_access' => true,
            'free_access_reason_code' => '22',
            'permit_type_code' => null,
            'issuing_labour_office_code' => null,
            'permit_identifier' => null,
            'permit_from' => null,
            'permit_to' => null,
        ];

        return $source;
    }

    /** @return array<string,mixed> */
    private static function identity(string $citizenship = 'CZ'): array
    {
        $identity = PayrollRegistrationA1SnapshotBuilderTest::identity();
        $identity['citizenship_country_code'] = $citizenship;
        $identity['birth_country_code'] = $citizenship;

        return $identity;
    }

    /** @param array<string,mixed> $source */
    private static function a1(array $source, string $citizenship = 'CZ'): PayrollRegistrationA1Snapshot
    {
        return (new PayrollRegistrationA1SnapshotBuilder())->build(
            $source,
            self::identity($citizenship),
            ['supplier_id' => 11, 'employee_id' => 41, 'employment_id' => 51, 'effective_on' => self::START],
        );
    }

    /** @param array<string,mixed> $identity */
    private static function a1Payload(PayrollRegistrationA1Snapshot $a1, array $identity): PayrollRegistrationXmlPayload
    {
        return new PayrollRegistrationXmlPayload(
            identity: self::snapshot($identity, $a1),
            interaction: new PayrollRegistrationInteraction('REGZEC25', 'direct_full_registration', 1),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: self::PREPARED_A1,
            expectedStartOn: null,
            actualStartOn: self::START,
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
            productName: 'MyÚčto.cz',
            productVersion: '6.49.0',
            specVersion: JmhzSpecVersion::V2027,
        );
    }

    /** @param array<string,mixed> $data */
    private static function eventPayload(
        int $action,
        array $data,
        string $preparedOn = self::PREPARED,
        JmhzSpecVersion $version = JmhzSpecVersion::V2027,
    ): PayrollRegistrationXmlPayload {
        $interaction = [
            2 => 'termination', 3 => 'change', 4 => 'correction',
            5 => 'variable_symbol_transfer', 6 => 'czech_legislation_start',
            7 => 'czech_legislation_end', 8 => 'cancellation',
        ][$action];

        return new PayrollRegistrationXmlPayload(
            identity: self::snapshot(self::identity(), null),
            interaction: new PayrollRegistrationInteraction('REGZEC25', $interaction, $action),
            sequenceNumber: 1,
            formGuid: '12345678-1234-1234-1234-123456789ABC',
            preparedOn: $preparedOn,
            expectedStartOn: null,
            actualStartOn: null,
            employerVariableSymbol: '1100000007',
            employerName: 'Syntetický zaměstnavatel s.r.o.',
            csszWorkplaceCode: '110',
            eventSnapshot: [
                'schema_reference' => 'payroll-registration-event-snapshot.v1',
                'supplier_id' => 11,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'interaction' => $interaction,
                'action_code' => $action,
                'effective_on' => self::EFFECTIVE,
                'notification_trigger_on' => self::EFFECTIVE,
                'person_external_identifier' => ['id' => 61, 'row_version' => 1, 'value' => '1000000001'],
                'employment_external_identifier' => ['id' => 71, 'row_version' => 1, 'value' => '200000000000000000002'],
                'employer' => [
                    'variable_symbol' => '1100000007',
                    'name' => 'Syntetický zaměstnavatel s.r.o.',
                    'workplace_code' => '110',
                ],
                'data' => $data,
                'source' => ['kind' => 'synthetic', 'reference' => 'synthetic:' . $interaction],
            ],
            productName: 'MyÚčto.cz',
            productVersion: '6.49.0',
            specVersion: $version,
        );
    }

    /** @param array<string,mixed> $identity */
    private static function snapshot(array $identity, ?PayrollRegistrationA1Snapshot $a1): PayrollRegistrationIdentitySnapshot
    {
        return new PayrollRegistrationIdentitySnapshot(
            scope: [
                'supplier_id' => 11,
                'submission_id' => 21,
                'source_revision_id' => null,
                'employee_id' => 41,
                'employment_id' => 51,
                'environment' => 'production',
                'agenda_code' => 'REGZEC25',
                'effective_on' => self::START,
            ],
            identity: $identity,
            identifiers: [
                'birth_number' => $identity['citizenship_country_code'] === 'CZ' ? '9152031234' : null,
                'ecp' => null,
                'vcp' => null,
                'foreign_tax_identifier' => null,
            ],
            employmentExternalIdentifier: null,
            registrationEligibility: ['status' => 'not_applicable', 'basis' => 'agenda_not_prezec'],
            sourceVersions: $a1 === null ? [] : ['regzec_a1' => $a1->source],
            regzecA1: $a1,
        );
    }
}
