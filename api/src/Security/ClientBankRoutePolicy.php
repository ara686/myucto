<?php

declare(strict_types=1);

namespace MyInvoice\Security;

/** Klientská banka zpřístupňuje výpisy; sdílená práva banky nesmí otevřít další agendy. */
final class ClientBankRoutePolicy
{
    public static function allows(string $method, string $path, string $permission): bool
    {
        if ($permission !== 'bank' && !str_starts_with($permission, 'bank.')) return true;

        $method = strtoupper($method);
        if ($method === 'HEAD') $method = 'GET';
        $patterns = match ($method) {
            'GET' => [
                '#^/api/purchase-invoices/[0-9]+/bank-matching$#D',
                '#^/api/bank-statements(?:/account-balances|/[0-9]+(?:/(?:download|pdf|export-gpc|match-suggestions))?)?$#D',
                '#^/api/bank-transactions(?:/[0-9]+/(?:match-candidates|split-suggestions))?$#D',
            ],
            'POST' => [
                '#^/api/purchase-invoices/[0-9]+/bank-matching/retry$#D',
                '#^/api/bank-statements/(?:upload|upload-pdf)$#D',
                '#^/api/bank-transactions/[0-9]+/(?:match|unmatch|ignore|unignore)$#D',
                '#^/api/bank-match-suggestions/[0-9]+/(?:accept|reject)$#D',
            ],
            default => [],
        };
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path) === 1) return true;
        }
        return false;
    }
}
