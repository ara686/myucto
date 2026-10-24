<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet;

use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Eet\Diagnostics\EetDiagnosticsRequest;
use MyInvoice\Service\Eet\Diagnostics\EetDiagnosticsTransport;

/**
 * Ověřovací test spojení s EET (`overeni=true`) nad nastavením firmy.
 *
 * Úspěch je odpověď `Chyba kod=0` — ověřuje identitu, certifikát a dostupnost,
 * nikoli evidenci tržby. Výsledek se nezapisuje do `eet_records`; do auditu jde
 * jen souhrn bez XML.
 */
final class EetDiagnosticsService
{
    public function __construct(
        private readonly EetSettingsService $settings,
        private readonly EetDeviceRepository $devices,
        private readonly EetCertificateVault $certificates,
        private readonly EetDiagnosticsTransport $transport,
        private readonly ActivityLogger $logger,
    ) {}

    /**
     * @return array{ok:bool,outcome:string,error_code:?int,message:?string,duration_ms:int,environment:string,device_id:int,certificate_id:?int}
     */
    public function run(int $supplierId, ?int $deviceId, ?int $userId): array
    {
        $settings = $this->settings->get($supplierId);
        $eic = $settings['eic'];
        if (!is_string($eic) || $eic === '') {
            throw new EetException(EetException::NOT_CONFIGURED, 'Pro ověření spojení nejdřív vyplňte DIČ poplatníka (EIČ).', 422);
        }
        $device = $this->resolveDevice($supplierId, $deviceId ?? $settings['default_device_id']);
        $environment = (string) $settings['environment'];
        $certificate = $this->certificates->activeMetadata($supplierId, $environment, $eic);
        if ($certificate === null) {
            throw new EetException(EetException::NOT_CONFIGURED, 'Chybí platný pokladní certifikát pro zvolené prostředí se shodným DIČ.', 422);
        }

        $outcome = $this->transport->verify(new EetDiagnosticsRequest(
            $supplierId,
            $environment,
            $eic,
            (int) $device['unit_number'],
            (string) $device['code'],
            (int) $settings['response_timeout_ms'],
        ));

        $result = [
            'ok' => $outcome->ok,
            'outcome' => $outcome->outcome,
            'error_code' => $outcome->errorCode,
            'message' => $outcome->message,
            'duration_ms' => $outcome->durationMs,
            'environment' => $environment,
            'device_id' => (int) $device['id'],
            'certificate_id' => $outcome->certificateId ?? (int) $certificate['id'],
        ];
        $this->logger->log(
            'eet_diagnostics',
            $userId,
            'eet_device',
            (int) $device['id'],
            [
                'ok' => $result['ok'],
                'outcome' => $result['outcome'],
                'error_code' => $result['error_code'],
                'duration_ms' => $result['duration_ms'],
                'environment' => $environment,
            ],
            null,
            null,
            $supplierId,
        );

        return $result;
    }

    /** @return array<string,mixed> */
    private function resolveDevice(int $supplierId, ?int $deviceId): array
    {
        if ($deviceId !== null) {
            $device = $this->devices->find($supplierId, $deviceId);
            if ($device === null) {
                throw EetException::notFound('Zařízení EET nebylo nalezeno.');
            }
            if (!$device['active']) {
                throw EetException::validation('Zařízení EET není aktivní.', ['field' => 'device_id']);
            }
            return $device;
        }
        foreach ($this->devices->listFor($supplierId) as $device) {
            if ($device['active']) {
                return $device;
            }
        }
        throw new EetException(EetException::NOT_CONFIGURED, 'Firma nemá žádné aktivní zařízení EET.', 422);
    }
}
