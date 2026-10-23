<?php

declare(strict_types=1);

namespace MyInvoice\Service\Auth;

/**
 * Jediné místo, které rozhoduje, zda je `app.pepper` použitelný.
 *
 * Používá ho produkční guard v {@see \MyInvoice\Bootstrap}, HKDF fallback
 * v {@see SecretEncryption} i diagnostický balíček, aby se nemohly rozejít:
 * hodnota ze vzorové konfigurace je veřejná, takže ochranu nedává žádnou.
 */
final class PepperPolicy
{
    /** Instalátory generují `openssl rand -base64 32` (44 znaků); 16 je spodní mez pro ruční hodnoty. */
    public const MIN_LENGTH = 16;

    /** Známé zástupné hodnoty ze vzorů a návodů (porovnává se bez ohledu na velikost písmen). */
    private const PLACEHOLDERS = [
        'change-me',
        'changeme',
        'change_me',
    ];

    public static function isPlaceholder(string $pepper): bool
    {
        return in_array(strtolower(trim($pepper)), self::PLACEHOLDERS, true);
    }

    /** Důvod, proč pepper v produkci nestačí, nebo null, když je v pořádku. */
    public static function productionProblem(string $pepper): ?string
    {
        if (trim($pepper) === '') {
            return 'cfg.app.pepper není nastaven (vygeneruj: openssl rand -base64 32). V produkci je povinný.';
        }
        if (self::isPlaceholder($pepper)) {
            return 'cfg.app.pepper má zástupnou hodnotu ze vzorové konfigurace (vygeneruj: openssl rand -base64 32).';
        }
        if (strlen(trim($pepper)) < self::MIN_LENGTH) {
            return 'cfg.app.pepper je příliš krátký, minimum je ' . self::MIN_LENGTH
                . ' znaků (vygeneruj: openssl rand -base64 32).';
        }
        return null;
    }
}
