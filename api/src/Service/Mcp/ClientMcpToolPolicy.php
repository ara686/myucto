<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mcp;

use MyInvoice\Security\PermissionChecker;
use MyInvoice\Security\PermissionResolver;
use MyInvoice\Security\RoutePermissionMap;
use MyInvoice\Service\Tenant\ClientRoutePolicy;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Klientský katalog je fail-closed výběr nástrojů s deklarovanými API operacemi. */
final class ClientMcpToolPolicy
{
    public function __construct(
        private readonly PermissionResolver $roles,
        private readonly PermissionChecker $checker,
        private readonly RoutePermissionMap $routes,
        private readonly ClientRoutePolicy $clientRoutes,
    ) {}

    /** @return list<string> */
    public function allowed(Request $request): array
    {
        $role = $this->roles->resolve($request);
        $contracts = json_decode((string) file_get_contents(__DIR__ . '/../../../../MCP/src/client-tools.json'), true, 32, JSON_THROW_ON_ERROR);
        $allowed = [];
        foreach ($contracts as $name => $operations) {
            foreach ($operations as [$method, $path]) {
                $route = $this->routes->match($method, $path);
                if ($route === null || !$this->clientRoutes->allowsApiRequest($method, $path)) continue 2;
                if ($route->kind === RoutePermissionMap::SELF_SERVICE) continue;
                if ($route->kind !== RoutePermissionMap::PERMISSION || $route->key === null
                    || !array_any([$route->key, ...$route->alternativeKeys],
                        fn (string $key): bool => $this->checker->allows($role, $key, $route->minimum))) continue 2;
            }
            $allowed[] = $name;
        }
        return $allowed;
    }
}
