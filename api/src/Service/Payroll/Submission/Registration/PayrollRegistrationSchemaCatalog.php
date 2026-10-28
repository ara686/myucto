<?php

declare(strict_types=1);

namespace MyInvoice\Service\Payroll\Submission\Registration;

use MyInvoice\Service\Payroll\Submission\Jmhz\JmhzSpecVersion;

final class PayrollRegistrationSchemaCatalog
{
    public const NS_PREZEC_2026 = 'http://schemas.cssz.cz/PREZEC/2026';
    public const NS_REGZEC_2026 = 'http://schemas.cssz.cz/REGZEC/2025';
    public const NS_REGZEC_2027 = 'http://schemas.cssz.cz/REGZEC/2027';

    /** Kód blokace podání, jehož verze (podle data události) v aplikaci není. */
    public const UNAVAILABLE_CODE = 'registration_spec_version_unavailable';

    /**
     * Připnuté XSD REGZEC27 (balík 1.5, WP-1): `[vstupní soubor, SHA-256,
     * baseTypes, SHA-256 baseTypes]` relativně k `api/xsd/jmhz`. Dokud je
     * `null`, REGZEC s událostí od 1. 1. 2027 se nesestaví. Integrátor
     * doplní cesty a otisky po připnutí balíku.
     *
     * @var array{0:string,1:string,2:string,3:string}|null
     */
    private const REGZEC27_SCHEMA = null;

    /**
     * Ročníkový formulář pro podání s událostí k danému dni (nástup, skončení,
     * platnost změny). Volba verze je v {@see JmhzSpecVersion}; tady se jen
     * překládá na formulář a fail-closed odmítne verze, které aplikace neumí.
     *
     * @param string $documentType formulář tak, jak ho určila interakce (`REGZEC25`, `PREZEC26`)
     */
    public function documentTypeFor(string $documentType, string $eventDate): string
    {
        $version = JmhzSpecVersion::forRegistrationEventDate($eventDate);
        if ($version === JmhzSpecVersion::V2026) {
            return $documentType;
        }
        $versioned = match ($documentType) {
            'REGZEC25' => 'REGZEC27',
            default => null,
        };
        if ($versioned === null) {
            // PREZEC27: `eType` obálky ČSSZ zatím doložený není (O-4), takže
            // by takové podání nešlo ani odeslat.
            throw new PayrollRegistrationXmlException(
                self::UNAVAILABLE_CODE,
                "Formulář {$documentType} s datem události od 1. 1. 2027 se podává"
                    . ' podle specifikace JMHZ 2027, kterou ČSSZ pro tenhle formulář'
                    . ' zatím nezveřejnila. Podání se proto nepřipraví; podejte ho'
                    . ' přes portál ČSSZ.',
            );
        }
        if (!self::isPinned($versioned)) {
            throw new PayrollRegistrationXmlException(
                self::UNAVAILABLE_CODE,
                "Událost k {$eventDate} se hlásí formulářem {$versioned}"
                    . ' (specifikace JMHZ 2027), který tahle verze aplikace zatím'
                    . ' neumí sestavit. Podání se proto nepřipraví. Události do'
                    . ' 31. 12. 2026 připravíte jako dosud.',
            );
        }

        return $versioned;
    }

    public static function isPinned(string $documentType): bool
    {
        return match ($documentType) {
            'PREZEC26', 'REGZEC25' => true,
            'REGZEC27' => self::REGZEC27_SCHEMA !== null,
            default => false,
        };
    }

    /** @return array{path:string,namespace:string} */
    public function schemaFor(string $documentType): array
    {
        $root = dirname(__DIR__, 5) . '/xsd/jmhz';
        $baseTypesHash = '0ed12320dc9f9230fb60182ac0389dd10b2b76ea5e2aaacf3f71715cbfa82e58';
        $definition = match ($documentType) {
            'PREZEC26' => [
                $root . '/prezec-1.2/PREZEC26 1.2.xsd',
                '117d56be4a79ebec3c1684a4b32b94df6dbeb99a7533489359bee48cb9a11b0a',
                $root . '/prezec-1.2/baseTypes2.xsd',
                self::NS_PREZEC_2026,
            ],
            'REGZEC25' => [
                $root . '/regzec-1.4.0.4/REGZEC25.xsd',
                'bbf96586cccd36457283f8474a982d3bee8ae98bbdba120f240065aa6d40a83b',
                $root . '/regzec-1.4.0.4/baseTypes2.xsd',
                self::NS_REGZEC_2026,
            ],
            'REGZEC27' => self::REGZEC27_SCHEMA === null
                ? throw new PayrollRegistrationXmlException(
                    self::UNAVAILABLE_CODE,
                    'Předloha formuláře REGZEC27 (specifikace JMHZ 2027) v téhle'
                        . ' verzi aplikace zatím není, podání se proto nedá'
                        . ' zkontrolovat ani odeslat.',
                )
                : [
                    $root . '/' . self::REGZEC27_SCHEMA[0],
                    self::REGZEC27_SCHEMA[1],
                    $root . '/' . self::REGZEC27_SCHEMA[2],
                    self::NS_REGZEC_2027,
                ],
            // Výjimka tu zůstává schválně: bez předlohy formuláře se podání
            // nedá ani zkontrolovat, natož odeslat. Není to neúplný vstup
            // účetní, ale požadavek na formulář, který appka neumí.
            default => throw new PayrollRegistrationXmlException(
                'registration_schema_unavailable',
                "Registrační formulář ČSSZ „{$documentType}“ tahle verze "
                    . 'neumí připravit. Podporované jsou přihlášení a oznámení '
                    . 'REGZEC25 a částečné přihlášení PREZEC26; ostatní podejte '
                    . 'přes portál ČSSZ.',
            ),
        };
        if ($documentType === 'REGZEC27' && self::REGZEC27_SCHEMA !== null) {
            $baseTypesHash = self::REGZEC27_SCHEMA[3];
        }
        [$entry, $entryHash, $dependency, $namespace] = $definition;
        foreach ([
            $entry => $entryHash,
            $dependency => $baseTypesHash,
        ] as $path => $expectedHash) {
            $actualHash = is_file($path) ? hash_file('sha256', $path) : false;
            if ($actualHash === false
                || !hash_equals($expectedHash, $actualHash)
            ) {
                // Taky vědomě výjimka: kdyby se předloha jen „přeskočila",
                // odešlo by se na ČSSZ nezkontrolované podání. Účetní s tím
                // nic neudělá, ale musí vědět, že to není chyba jejích dat.
                throw new PayrollRegistrationXmlException(
                    'registration_schema_integrity_failed',
                    'Předlohy formulářů ČSSZ v této instalaci chybí nebo se '
                        . 'liší od ověřených. Podání proto nejde zkontrolovat — '
                        . 'není to chybou vašich údajů, obraťte se na podporu.',
                );
            }
        }

        return ['path' => $entry, 'namespace' => $namespace];
    }
}
