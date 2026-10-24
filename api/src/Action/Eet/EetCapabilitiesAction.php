<?php

declare(strict_types=1);

namespace MyInvoice\Action\Eet;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Security\AccessLevel;
use MyInvoice\Service\Eet\EetCertificateVault;
use MyInvoice\Service\Eet\EetSettingsService;
use MyInvoice\Service\License\CommercialFeatureAccess;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Schopnosti modulu EET pro web i budoucí mobil: stav, režim, podporované
 * a nepodporované scénáře, verze kontraktu a efektivní práva uživatele.
 * Starší klient podle `contract_version` a seznamů pozná, co neumí.
 */
final class EetCapabilitiesAction
{
    public const CONTRACT_VERSION = '2026-10.1';
    public const SUPPORTED_SCENARIOS = ['cash', 'card_terminal_confirmed', 'qr_in_person', 'refund', 'correction'];
    public const UNSUPPORTED_SCENARIOS = ['foreign_currency', 'credit', 'security_deposit', 'delegation'];

    public function __construct(
        private readonly EetSettingsService $settings,
        private readonly EetCertificateVault $certificates,
        private readonly CommercialFeatureAccess $commercial,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        if (($denied = EetActionSupport::deny($request, $response, 'eet', AccessLevel::READ)) !== null) {
            return $denied;
        }
        $supplierId = SupplierGuard::currentId($request);
        $settings = $this->settings->get($supplierId);
        $certificate = $this->certificates->activeMetadata(
            $supplierId,
            (string) $settings['environment'],
            is_string($settings['eic']) ? $settings['eic'] : null,
        );

        return Json::ok($response, [
            'contract_version' => self::CONTRACT_VERSION,
            'enabled' => $settings['enabled'],
            'environment' => $settings['environment'],
            'regime' => $settings['regime'],
            'eic' => $settings['eic'],
            'active_from' => $settings['active_from'],
            'supported_scenarios' => self::SUPPORTED_SCENARIOS,
            'unsupported_scenarios' => self::UNSUPPORTED_SCENARIOS,
            'permissions' => EetActionSupport::permissions($request),
            'default_device_id' => $settings['default_device_id'],
            'has_active_certificate' => $certificate !== null,
            'certificate_expires_at' => $certificate['not_after'] ?? null,
            'commercial_license' => $this->commercial->isAvailable(),
        ]);
    }
}
