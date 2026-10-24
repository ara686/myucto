<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Diagnostics;

use MyInvoice\Service\Eet\EetCertificateVault;
use MyInvoice\Service\Eet\Transport\EetEnvironment;
use MyInvoice\Service\Eet\Transport\EetMessageHeader;
use MyInvoice\Service\Eet\Transport\EetSaleData;
use MyInvoice\Service\Eet\Transport\EetTimeouts;
use MyInvoice\Service\Eet\Transport\EetTransportInterface;

/**
 * Ověřovací zpráva přes skutečný transport EET s aktivním certifikátem firmy.
 *
 * Zpráva nese `overeni=true`, neodebírá číslo z řady zařízení a nic se z ní
 * neukládá do evidence. Pořadové číslo je jen technický identifikátor testu.
 */
final class TransportEetDiagnostics implements EetDiagnosticsTransport
{
    private const DIAGNOSTIC_AMOUNT = '1.00';

    public function __construct(
        private readonly EetCertificateVault $vault,
        private readonly EetTransportInterface $transport,
    ) {}

    public function verify(EetDiagnosticsRequest $request): EetDiagnosticsOutcome
    {
        $environment = EetEnvironment::from($request->environment);
        $active = $this->vault->activeCredential($request->supplierId, $environment, $request->eic);
        if ($active === null) {
            return new EetDiagnosticsOutcome(false, 'certificate_missing', null, 'Chybí platný pokladní certifikát pro zvolené prostředí.', 0);
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Prague'));
        // Pojmenované argumenty odpovídají kontraktu transportní vrstvy (EetSaleData,
        // EetTimeouts); při jeho změně se upraví jen tohle místo.
        $sale = new EetSaleData(
            eic: $request->eic,
            unitNumber: $request->unitNumber,
            deviceCode: $request->deviceCode,
            sequenceNumber: 'OVERENI-' . $now->format('YmdHis'),
            saleAt: $now,
            totalAmount: self::DIAGNOSTIC_AMOUNT,
        );
        $result = $this->transport->send(
            $environment,
            $sale,
            EetMessageHeader::create(true, true),
            $active->credential,
            EetTimeouts::fromResponseTimeoutMs($request->responseTimeoutMs),
        );

        $outcome = $result->outcome instanceof \BackedEnum ? (string) $result->outcome->value : (string) $result->outcome;

        return new EetDiagnosticsOutcome(
            $result->errorCode === 0,
            $outcome,
            $result->errorCode,
            $result->errorMessage,
            (int) $result->durationMs,
            $active->certificateId,
        );
    }
}
