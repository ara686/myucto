<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Certificate;

use MyInvoice\Service\Eet\EetException;
use MyInvoice\Service\Eet\EetIdentifiers;

/**
 * Ověří nahraný pokladní certifikát EET (PKCS#12 + heslo) dřív, než se uloží.
 *
 * Kontroluje: čitelnost souboru, přítomnost soukromého klíče a jeho shodu
 * s certifikátem, RSA alespoň 2048 bitů, CN = DIČ poplatníka (EIČ), účel
 * „Digital Signature", dobu platnosti a řetězec k připnuté autoritě EET, podle
 * které se určí prostředí (Playground / produkce).
 *
 * Soubor se po ověření znovu zabalí aktuálním šifrováním PKCS#12 (AES), takže
 * pozdější podepisování nepotřebuje legacy provider OpenSSL kvůli RC2 souborům.
 * Text chyb OpenSSL se do odpovědi nikdy nepropisuje.
 */
final class EetCertificateInspector
{
    private const MAX_PKCS12_BYTES = 65536;
    private const MAX_CHAIN_DEPTH = 4;

    public function __construct(private readonly EetTrustStore $trust) {}

    public function inspect(
        #[\SensitiveParameter] string $pkcs12,
        #[\SensitiveParameter] string $password,
        ?\DateTimeImmutable $now = null,
    ): EetInspectedCertificate {
        $now ??= new \DateTimeImmutable();
        if ($pkcs12 === '' || strlen($pkcs12) > self::MAX_PKCS12_BYTES) {
            throw EetException::validation('Nahrajte soubor pokladního certifikátu ve formátu P12 (nejvýš 64 kB).');
        }
        if (!function_exists('openssl_pkcs12_read')) {
            throw new EetException('eet_openssl_unavailable', 'Server nepodporuje práci s certifikáty PKCS#12.', 503);
        }

        while (openssl_error_string() !== false) {
        }
        $bundle = [];
        if (!@openssl_pkcs12_read($pkcs12, $bundle, $password)) {
            $errors = '';
            while (($error = openssl_error_string()) !== false) {
                $errors .= $error . "\n";
            }
            // OpenSSL 3 má RC2 (starší exporty PKCS#12) jen v legacy provideru.
            // Se špatným heslem to nesouvisí, proto vlastní hláška.
            if (str_contains($errors, 'unsupported')) {
                throw new EetException(
                    'eet_certificate_legacy_encryption',
                    'Soubor P12 používá zastaralé šifrování (RC2), které server bez legacy provideru OpenSSL nepřečte. '
                    . 'Heslo za tím není. Převeďte soubor příkazy openssl pkcs12 -in puvodni.p12 -legacy -nodes -out docasny.pem '
                    . 'a openssl pkcs12 -export -in docasny.pem -out novy.p12 (docasny.pem pak smažte), nebo požádejte správce serveru o zapnutí legacy provideru.',
                    422,
                );
            }
            throw new EetException(
                'eet_certificate_unreadable',
                'Soubor certifikátu nelze otevřít. Zkontrolujte soubor a heslo.',
                422,
            );
        }

        try {
            return $this->inspectBundle($bundle, $pkcs12, $password, $now);
        } finally {
            unset($bundle);
        }
    }

    /**
     * Ověření už rozbaleného PKCS#12 (`cert`, `pkey`, `extracerts` jako
     * z `openssl_pkcs12_read`). Samostatně kvůli testům stavů, které běžný
     * export PKCS#12 vůbec nevytvoří (soubor bez klíče, cizí klíč).
     *
     * @param array<string,mixed> $bundle
     */
    public function inspectBundle(
        #[\SensitiveParameter] array $bundle,
        #[\SensitiveParameter] string $pkcs12,
        #[\SensitiveParameter] string $password,
        \DateTimeImmutable $now,
    ): EetInspectedCertificate {
        $certificate = is_string($bundle['cert'] ?? null) ? $bundle['cert'] : '';
        $privateKey = is_string($bundle['pkey'] ?? null) ? $bundle['pkey'] : '';
        $extraCerts = array_values(array_filter((array) ($bundle['extracerts'] ?? []), 'is_string'));
        if ($certificate === '') {
            throw new EetException('eet_certificate_invalid', 'Soubor neobsahuje certifikát.', 422);
        }
        if ($privateKey === '') {
            throw new EetException('eet_certificate_no_private_key', 'Soubor neobsahuje soukromý klíč. Nahrajte P12 se soukromým klíčem.', 422);
        }
        if (!@openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new EetException('eet_certificate_key_mismatch', 'Soukromý klíč v souboru nepatří k certifikátu.', 422);
        }
        $key = @openssl_pkey_get_private($privateKey);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (!is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || (int) ($details['bits'] ?? 0) < 2048) {
            throw new EetException('eet_certificate_key_type', 'Pokladní certifikát musí mít klíč RSA o délce alespoň 2048 bitů.', 422);
        }

        $parsed = @openssl_x509_parse($certificate);
        $fingerprint = @openssl_x509_fingerprint($certificate, 'sha256');
        if (!is_array($parsed) || !is_string($fingerprint) || $fingerprint === '') {
            throw new EetException('eet_certificate_invalid', 'Certifikát se nepodařilo přečíst.', 422);
        }

        $cn = self::nameField($parsed['subject'] ?? [], 'CN');
        $eic = EetIdentifiers::normalizeEic($cn);
        if (!EetIdentifiers::isEic($eic)) {
            throw new EetException('eet_certificate_subject', 'Certifikát nemá v CN platné DIČ poplatníka (EIČ). Nejde o pokladní certifikát EET.', 422);
        }

        $keyUsage = (string) ($parsed['extensions']['keyUsage'] ?? '');
        if (!str_contains($keyUsage, 'Digital Signature')) {
            throw new EetException('eet_certificate_key_usage', 'Certifikát není určen k elektronickému podpisu (chybí Digital Signature).', 422);
        }

        $notBefore = (new \DateTimeImmutable('@' . (int) ($parsed['validFrom_time_t'] ?? 0)))->setTimezone($now->getTimezone());
        $notAfter = (new \DateTimeImmutable('@' . (int) ($parsed['validTo_time_t'] ?? 0)))->setTimezone($now->getTimezone());
        if ($notBefore > $now) {
            throw new EetException('eet_certificate_not_yet_valid', 'Certifikát ještě není platný (platnost od ' . $notBefore->format('j. n. Y H:i') . ').', 422);
        }
        if ($notAfter <= $now) {
            throw new EetException('eet_certificate_expired', 'Platnost certifikátu skončila ' . $notAfter->format('j. n. Y H:i') . '.', 422);
        }

        $environment = $this->trustedEnvironment($certificate, $now);
        if ($environment === null) {
            throw new EetException(
                'eet_certificate_untrusted',
                'Certifikát nevydala certifikační autorita EET (Playground ani produkční). Nahrajte pokladní certifikát z aplikace CA EET.',
                422,
            );
        }

        $normalized = '';
        $exported = @openssl_pkcs12_export($certificate, $normalized, $privateKey, $password, ['extracerts' => $extraCerts]);
        if (!$exported || $normalized === '') {
            $normalized = $pkcs12;
        }

        return new EetInspectedCertificate(
            $environment,
            $eic,
            mb_substr($cn, 0, 64),
            substr(strtoupper((string) ($parsed['serialNumberHex'] ?? '')), 0, 80),
            strtolower($fingerprint),
            mb_substr(self::issuerLabel($parsed['issuer'] ?? []), 0, 255),
            $notBefore,
            $notAfter,
            $normalized,
        );
    }

    /**
     * Prostředí autority, ke které certifikát vede platným podpisovým řetězcem
     * až ke kořeni z úložiště důvěry; `null`, když k žádné.
     */
    private function trustedEnvironment(string $certificate, \DateTimeImmutable $now): ?string
    {
        foreach ($this->trust->anchors() as $environment => $authorities) {
            if ($this->chainsTo($certificate, $authorities, $now, 0)) {
                return (string) $environment;
            }
        }
        return null;
    }

    /** @param list<string> $authorities */
    private function chainsTo(string $certificate, array $authorities, \DateTimeImmutable $now, int $depth): bool
    {
        if ($depth > self::MAX_CHAIN_DEPTH) {
            return false;
        }
        $parsed = @openssl_x509_parse($certificate);
        if (!is_array($parsed)) {
            return false;
        }
        foreach ($authorities as $authority) {
            $ca = @openssl_x509_parse($authority);
            if (!is_array($ca) || ($ca['subject'] ?? null) != ($parsed['issuer'] ?? null)) {
                continue;
            }
            if (!str_contains((string) ($ca['extensions']['basicConstraints'] ?? ''), 'CA:TRUE')) {
                continue;
            }
            $from = (int) ($ca['validFrom_time_t'] ?? 0);
            $to = (int) ($ca['validTo_time_t'] ?? 0);
            if ($from > $now->getTimestamp() || $to <= $now->getTimestamp()) {
                continue;
            }
            if (@openssl_x509_verify($certificate, $authority) !== 1) {
                continue;
            }
            $selfSigned = ($ca['subject'] ?? null) == ($ca['issuer'] ?? null)
                && @openssl_x509_verify($authority, $authority) === 1;
            if ($selfSigned || $this->chainsTo($authority, $authorities, $now, $depth + 1)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $name */
    private static function nameField(array $name, string $field): string
    {
        $value = $name[$field] ?? '';
        if (is_array($value)) {
            $value = (string) (reset($value) ?: '');
        }
        return trim((string) $value);
    }

    /** @param array<string,mixed> $issuer */
    private static function issuerLabel(array $issuer): string
    {
        $parts = [];
        foreach (['CN', 'O', 'C'] as $field) {
            $value = self::nameField($issuer, $field);
            if ($value !== '') {
                $parts[] = $field . '=' . $value;
            }
        }
        return implode(', ', $parts);
    }
}
