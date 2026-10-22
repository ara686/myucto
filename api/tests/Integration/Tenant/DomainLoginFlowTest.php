<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Tenant;

use MyInvoice\Action\Auth\DomainLoginAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Middleware\DomainLoginSessionMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Auth\DomainLoginException;
use MyInvoice\Service\Auth\DomainLoginService;
use MyInvoice\Service\Auth\SessionCookieFactory;
use MyInvoice\Service\Auth\SessionAuthContext;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Tenant\TenantDomainContext;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

#[Group('integration')]
final class DomainLoginFlowTest extends TestCase
{
    private Connection $db;
    private DomainLoginService $login;
    private SessionManager $sessions;
    private SessionCookieFactory $cookies;
    private IpMatcher $ipMatcher;
    private int $supplierId = 0;
    private int $userId = 0;
    private int $credentialId = 0;
    private int $domainId = 0;
    private string $hostname = '';

    protected function setUp(): void
    {
        if (!is_file(dirname(__DIR__, 4) . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $container = Bootstrap::buildContainer();
            $this->db = $container->get(Connection::class);
            $this->login = $container->get(DomainLoginService::class);
            $this->sessions = $container->get(SessionManager::class);
            $this->cookies = $container->get(SessionCookieFactory::class);
            $this->ipMatcher = $container->get(IpMatcher::class);
            // Connection je lazy; ověř ji uvnitř stejného skip guardu, jinak
            // nedostupná test DB skončí jako šest setup errorů místo explicitního skipu.
            $pdo = $this->db->pdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nebo DB nejsou dostupné: ' . $e->getMessage());
        }

        $this->supplierId = (int) ($pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn() ?: 0);
        $clientRoleId = (int) ($pdo->query(
            "SELECT id FROM roles WHERE system_key = 'client' LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($this->supplierId < 1 || $clientRoleId < 1) {
            $this->markTestSkipped('Chybí syntetický supplier nebo klientská role.');
        }

        $suffix = bin2hex(random_bytes(6));
        $pdo->prepare(
            "INSERT INTO users
                (email, password_hash, name, role, role_id, locale, is_active)
             VALUES (?, ?, ?, 'readonly', ?, 'cs', 1)"
        )->execute([
            'domain-login-' . $suffix . '@example.invalid',
            str_repeat('x', 60),
            'Domain Login Fixture',
            $clientRoleId,
        ]);
        $this->userId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO user_suppliers (user_id, supplier_id) VALUES (?, ?)')
            ->execute([$this->userId, $this->supplierId]);

        $credential = random_bytes(32);
        $pdo->prepare(
            'INSERT INTO webauthn_credentials
                (user_id, credential_id, credential_id_hash, public_key, sign_count,
                 transports_json, label, created_at)
             VALUES (?, ?, ?, ?, 0, ?, ?, UTC_TIMESTAMP(6))'
        )->execute([
            $this->userId,
            $credential,
            hash('sha256', $credential, true),
            'synthetic-public-key',
            '["internal"]',
            'Syntetická passkey',
        ]);
        $this->credentialId = (int) $pdo->lastInsertId();

        $this->hostname = 'portal-' . $suffix . '.example.test';
        $domain = $container->get(SupplierDomainRepository::class)->create(
            $this->supplierId,
            $this->hostname,
            'portal',
            $this->userId,
        );
        $this->domainId = (int) $domain['id'];
        $pdo->prepare(
            "UPDATE supplier_domains
                SET status = 'active', verified_at = UTC_TIMESTAMP(6), is_primary_portal = 1
              WHERE id = ?"
        )->execute([$this->domainId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        try {
            $pdo = $this->db->pdo();
        } catch (\Throwable) {
            return;
        }
        if ($this->domainId > 0) {
            $pdo->prepare('DELETE FROM supplier_domains WHERE id = ?')->execute([$this->domainId]);
        }
        if ($this->userId > 0) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$this->userId]);
        }
        $this->db->close();
    }

    public function testPasskeyLoginUsesExactHostPkceAndOneTimeHostOnlySession(): void
    {
        $verifier = self::opaqueToken();
        $flow = $this->start($verifier);
        self::assertStringContainsString('/login?domain_login_request=', $flow['login_url']);

        $authorized = $this->authorize($flow);
        parse_str((string) parse_url($authorized['redirect_url'], PHP_URL_QUERY), $callback);
        self::assertSame('https://' . $this->hostname, self::origin($authorized['redirect_url']));

        $wrongHost = $this->customRequest('other-' . $this->hostname);
        $this->assertExchangeFails(
            $wrongHost,
            $flow,
            $callback,
            $verifier,
            'invalid_domain_login_code',
        );
        $this->assertExchangeFails(
            $this->customRequest(),
            $flow,
            $callback,
            self::opaqueToken(),
            'invalid_domain_login_code',
        );

        $activity = $this->createMock(ActivityLogger::class);
        $activity->expects(self::once())->method('log')->with(
            'auth.domain_login',
            $this->userId,
            'supplier_domain',
            $this->domainId,
            ['supplier_id' => $this->supplierId],
            '192.0.2.15',
            'DomainLoginFlowTest/1.0',
            $this->supplierId,
        );
        $action = new DomainLoginAction($this->login, $this->cookies, $this->ipMatcher, $activity);
        $request = $this->customRequest()->withParsedBody([
            'request_token' => $flow['request_token'],
            'code' => $callback['code'] ?? '',
            'state' => $flow['state'],
            'code_verifier' => $verifier,
        ]);
        $response = $action->exchange($request, new Response());
        $payload = json_decode((string) $response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/portal/document-requests', $payload['return_path'] ?? null);
        self::assertSame($this->supplierId, $payload['supplier_id'] ?? null);
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+=[a-f0-9]{64};/', $cookie);
        self::assertStringContainsString('; HttpOnly; Path=/', $cookie);
        self::assertStringNotContainsString('Domain=', $cookie);

        self::assertSame(1, preg_match('/^[A-Za-z0-9_-]+=([a-f0-9]{64});/', $cookie, $match));
        $session = $this->sessions->load($match[1]);
        self::assertNotNull($session);
        self::assertSame($this->userId, $session['user_id']);
        self::assertSame('passkey', $session['auth_method']);
        self::assertSame('strong', $session['assurance_level']);
        self::assertSame($this->credentialId, $session['auth_credential_id']);

        $this->assertExchangeFails(
            $this->customRequest(),
            $flow,
            $callback,
            $verifier,
            'invalid_domain_login_code',
        );

        $expiredVerifier = self::opaqueToken();
        $expiredFlow = $this->start($expiredVerifier);
        $expiredAuthorized = $this->authorize($expiredFlow);
        parse_str((string) parse_url($expiredAuthorized['redirect_url'], PHP_URL_QUERY), $expiredCallback);
        $this->db->pdo()->prepare(
            'UPDATE supplier_domain_login_requests
                SET code_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)
              WHERE request_token_hash = ?'
        )->execute([hash('sha256', $expiredFlow['request_token'])]);
        $this->assertExchangeFails(
            $this->customRequest(),
            $expiredFlow,
            $expiredCallback,
            $expiredVerifier,
            'invalid_domain_login_code',
        );
    }

    public function testPortalExchangeSurvivesTemporaryCanonicalLogoutAndKeepsIndependentLogin(): void
    {
        $context = new SessionAuthContext('passkey', 'strong', new \DateTimeImmutable(), $this->credentialId);
        $independent = $this->sessions->create($this->userId, '192.0.2.15', 'Independent canonical login', $context);
        $temporary = $this->sessions->create($this->userId, '192.0.2.15', 'Temporary portal verification', $context);
        $verifier = self::opaqueToken();
        $flow = $this->start($verifier);
        $authorized = $this->login->authorize(
            $this->verifiedRequest($flow),
            $flow['request_token'],
            $flow['state'],
            ['id' => $this->userId],
            $this->sessions->load($temporary['token']),
        );
        parse_str((string) parse_url($authorized['redirect_url'], PHP_URL_QUERY), $callback);

        $this->sessions->destroy($temporary['token']);
        self::assertNull($this->sessions->load($temporary['token']));
        $result = $this->login->exchange(
            $this->customRequest(),
            $flow['request_token'],
            $callback['code'],
            $flow['state'],
            $verifier,
            '192.0.2.15',
        );
        $portalToken = $result['session']['token'];
        self::assertNotNull($this->sessions->load($portalToken));
        self::assertNotNull($this->sessions->load($independent['token']));

        $this->sessions->destroy($portalToken);
        self::assertNull($this->sessions->load($portalToken));
        self::assertNotNull($this->sessions->load($independent['token']));
    }

    public function testCanonicalAuthorizationStillRequiresCurrentMembership(): void
    {
        $verifier = self::opaqueToken();
        $flow = $this->start($verifier);
        $this->db->pdo()->prepare(
            'DELETE FROM user_suppliers WHERE user_id = ? AND supplier_id = ?'
        )->execute([$this->userId, $this->supplierId]);

        try {
            $this->authorize($flow);
            self::fail('Uživatel bez membershipu nesmí domain login autorizovat.');
        } catch (DomainLoginException $e) {
            self::assertSame('forbidden_supplier', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }
    }

    public function testStartRejectsCrossOriginAndInternalReturnPaths(): void
    {
        $challenge = self::pkceChallenge(self::opaqueToken());
        foreach ([
            '//attacker.example',
            '/\\attacker.example',
            '/admin/settings',
            '/accounting/journal',
            '/stock/reports',
            '/purchase-invoices/payment-orders',
            '/profile/api-tokens',
            '/login',
            '/invoice/' . str_repeat('a', 48),
            '/manual',
        ] as $returnPath) {
            try {
                $this->login->start(
                    $this->customRequest(),
                    $challenge,
                    $returnPath,
                    '192.0.2.15',
                );
                self::fail('Domain login smí pokračovat jen na klientskou routu stejného originu.');
            } catch (DomainLoginException $e) {
                self::assertSame('invalid_return_path', $e->errorCode);
                self::assertSame(400, $e->httpStatus);
            }
        }
    }

    public function testStartAcceptsEveryClientRouteFamilyAndLegacyAlias(): void
    {
        foreach ([
            '/clients/7?role=customer#history',
            '/invoices/new?type=proforma',
            '/purchase-invoices/7/edit',
            '/recurring/7',
            '/projects/7/edit',
            '/admin/price-list',
            '/logbook',
            '/stock/items/7',
            '/stock/fulfillment',
            '/profile/password',
            '/exchange?tab=export-issued',
            '/admin/export',
            '/admin/import?tab=purchase',
        ] as $returnPath) {
            $flow = $this->login->start(
                $this->customRequest(),
                self::pkceChallenge(self::opaqueToken()),
                $returnPath,
                '192.0.2.15',
            );
            $stmt = $this->db->pdo()->prepare(
                'SELECT return_path FROM supplier_domain_login_requests WHERE request_token_hash = ?'
            );
            $stmt->execute([hash('sha256', $flow['request_token'])]);
            self::assertSame($returnPath, $stmt->fetchColumn(), $returnPath);
        }
    }

    #[TestWith(['/profile/password?tab=passkeys', '/profile/passkeys'])]
    #[TestWith(['/profile/password?tab=totp', '/profile/password?tab=totp'])]
    public function testWebAuthnHandoffUsesCanonicalTargetAndSeparateValidatedCustomReturn(
        string $handoffPath,
        string $legacyReturnPath,
    ): void
    {
        $flow = $this->login->start(
            $this->customRequest(),
            self::pkceChallenge(self::opaqueToken()),
            '/invoices/7',
            '192.0.2.15',
            $handoffPath,
        );
        parse_str((string) parse_url($flow['login_url'], PHP_URL_QUERY), $loginQuery);
        self::assertSame(
            $handoffPath,
            $loginQuery['domain_login_handoff'] ?? null,
        );

        $stmt = $this->db->pdo()->prepare(
            'SELECT supplier_id, target_hostname, return_path
               FROM supplier_domain_login_requests
              WHERE request_token_hash = ?'
        );
        $stmt->execute([hash('sha256', $flow['request_token'])]);
        $stored = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame($this->supplierId, (int) ($stored['supplier_id'] ?? 0));
        self::assertSame($this->hostname, $stored['target_hostname'] ?? null);
        self::assertSame('/invoices/7', $stored['return_path'] ?? null);

        // Kompatibilita se starším klientem: citlivá cesta v return_path se
        // převede na canonical handoff a custom návrat nesmí vytvořit smyčku.
        $legacy = $this->login->start(
            $this->customRequest(),
            self::pkceChallenge(self::opaqueToken()),
            $legacyReturnPath,
            '192.0.2.15',
        );
        parse_str((string) parse_url($legacy['login_url'], PHP_URL_QUERY), $legacyQuery);
        self::assertSame(
            $handoffPath,
            $legacyQuery['domain_login_handoff'] ?? null,
        );
        $stmt->execute([hash('sha256', $legacy['request_token'])]);
        self::assertSame('/portal', ($stmt->fetch(\PDO::FETCH_ASSOC) ?: [])['return_path'] ?? null);
    }

    public function testWebAuthnHandoffRejectsUnmanifestedAndCrossOriginDestinations(): void
    {
        foreach ([
            '/profile/password?tab=unknown',
            '/profile/password?tab=totp&tab=passkeys',
            '/profile/password?tab=passkeys&tab=totp',
            '/admin/settings',
            '//attacker.example/profile/passkeys',
            'https://attacker.example/profile/passkeys',
        ] as $handoffPath) {
            try {
                $this->login->start(
                    $this->customRequest(),
                    self::pkceChallenge(self::opaqueToken()),
                    '/portal',
                    '192.0.2.15',
                    $handoffPath,
                );
                self::fail('Canonical handoff smí použít jen manifestovaný WebAuthn cíl.');
            } catch (DomainLoginException $e) {
                self::assertSame('invalid_handoff_path', $e->errorCode, $handoffPath);
                self::assertSame(400, $e->httpStatus, $handoffPath);
            }
        }
    }

    /** @return array{request_token:string,state:string,login_url:string,expires_in:int} */
    private function start(string $verifier): array
    {
        return $this->login->start(
            $this->customRequest(),
            self::pkceChallenge($verifier),
            '/portal/document-requests',
            '192.0.2.15',
        );
    }

    /** @param array{request_token:string,state:string} $flow @return array{redirect_url:string} */
    private function authorize(array $flow): array
    {
        return $this->login->authorize(
            $this->verifiedRequest($flow),
            $flow['request_token'],
            $flow['state'],
            [
                'id' => $this->userId,
                'is_superadmin' => false,
                'role_summary' => ['system_key' => 'client'],
            ],
            [
                'auth_method' => 'passkey',
                'assurance_level' => 'strong',
                'mfa_verified_at' => gmdate('Y-m-d H:i:s.u'),
                'auth_credential_id' => $this->credentialId,
            ],
        );
    }

    /**
     * @param array{request_token:string,state:string} $flow
     * @param array<string,mixed> $callback
     */
    private function assertExchangeFails(
        \Psr\Http\Message\ServerRequestInterface $request,
        array $flow,
        array $callback,
        string $verifier,
        string $expectedCode,
    ): void {
        try {
            $this->login->exchange(
                $request,
                $flow['request_token'],
                (string) ($callback['code'] ?? ''),
                $flow['state'],
                $verifier,
                '192.0.2.15',
            );
            self::fail('Neplatný domain-login exchange nesmí projít.');
        } catch (DomainLoginException $e) {
            self::assertSame($expectedCode, $e->errorCode);
        }
    }

    private function customRequest(?string $hostname = null): \Psr\Http\Message\ServerRequestInterface
    {
        $hostname ??= $this->hostname;
        return (new ServerRequestFactory())->createServerRequest(
            'POST',
            'https://' . $hostname . '/api/auth/domain-login/exchange',
            ['REMOTE_ADDR' => '192.0.2.15'],
        )->withHeader('User-Agent', 'DomainLoginFlowTest/1.0')
            ->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, new TenantDomainContext(
                TenantDomainContext::CUSTOM,
                $hostname,
                'https://' . $hostname,
                $this->domainId,
                $this->supplierId,
                'portal',
                'active',
            ));
    }

    /** @param array{request_token:string,state:string} $flow */
    private function verifiedRequest(array $flow): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->canonicalRequest()->withAttribute(DomainLoginSessionMiddleware::ATTR_VERIFIED_FLOW, [
            'request_token_hash' => hash('sha256', $flow['request_token']),
            'state_hash' => hash('sha256', $flow['state']),
        ]);
    }

    private function canonicalRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            'POST',
            'https://app.example.test/api/auth/domain-login/authorize',
        )->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, new TenantDomainContext(
            TenantDomainContext::CANONICAL,
            'app.example.test',
            'https://app.example.test',
        ));
    }

    private static function opaqueToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'] . '://' . $parts['host']
            : '';
    }
}
