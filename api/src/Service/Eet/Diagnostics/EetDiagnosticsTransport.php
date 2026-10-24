<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Diagnostics;

/**
 * Odeslání ověřovací zprávy EET. Produkční implementace odemkne certifikát
 * a jde přes transport ({@see TransportEetDiagnostics}); testy dosadí vlastní.
 */
interface EetDiagnosticsTransport
{
    public function verify(EetDiagnosticsRequest $request): EetDiagnosticsOutcome;
}
