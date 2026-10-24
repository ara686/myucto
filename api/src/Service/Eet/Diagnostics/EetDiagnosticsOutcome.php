<?php

declare(strict_types=1);

namespace MyInvoice\Service\Eet\Diagnostics;

/**
 * Výsledek ověřovací zprávy. `ok` znamená odpověď FS `Chyba kod=0` v ověřovacím
 * módu — to NENÍ potvrzení tržby a nikdy nenese POK.
 */
final class EetDiagnosticsOutcome
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $outcome,
        public readonly ?int $errorCode,
        public readonly ?string $message,
        public readonly int $durationMs,
        public readonly ?int $certificateId = null,
    ) {}
}
