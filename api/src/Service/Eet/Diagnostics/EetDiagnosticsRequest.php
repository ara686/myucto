<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Diagnostics;

/** Vstup ověřovací zprávy (`overeni=true`) — jen identifikátory, žádná tajemství. */
final class EetDiagnosticsRequest
{
    public function __construct(
        public readonly int $supplierId,
        public readonly string $environment,
        public readonly string $eic,
        public readonly int $unitNumber,
        public readonly string $deviceCode,
        public readonly int $responseTimeoutMs,
    ) {}
}
