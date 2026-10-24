<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

/**
 * Formáty identifikátorů datové zprávy EET (XSD EET v4) — jediné místo pravdy
 * pro nastavení, zařízení, certifikáty i tvorbu záznamů.
 */
final class EetIdentifiers
{
    /** `dic_popl`: CZ + 8 až 10 číslic. */
    public const EIC_PATTERN = '/^CZ[0-9]{8,10}$/D';

    /** `id_pokl`: XSD typ string20 s omezenou abecedou. */
    public const DEVICE_CODE_PATTERN = '/^[0-9a-zA-Z\.,:;\/#\-_ ]{1,20}$/D';

    /** `id_provoz`: kladné celé číslo, nejvýš šest číslic. */
    public const UNIT_NUMBER_MAX = 999999;

    public const ENVIRONMENTS = ['playground', 'production'];
    public const REGIMES = ['standard', 'exempt', 'eet_off'];
    public const UNIT_KINDS = ['premises', 'online', 'vehicle', 'mobile', 'other'];

    public static function isEic(string $value): bool
    {
        return preg_match(self::EIC_PATTERN, $value) === 1;
    }

    public static function isDeviceCode(string $value): bool
    {
        return preg_match(self::DEVICE_CODE_PATTERN, $value) === 1;
    }

    /** Normalizace vstupu EIČ: mezery pryč, velká písmena; validaci dělá volající. */
    public static function normalizeEic(string $value): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $value));
    }
}
