<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Auth;

use MyInvoice\Infrastructure\Cache\EntityCache;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\TenantDomainMiddleware;
use MyInvoice\Middleware\DomainLoginSessionMiddleware;
use MyInvoice\Repository\SupplierDomainRepository;
use MyInvoice\Repository\UserSupplierRepository;
use MyInvoice\Security\UserRoleProfile;
use MyInvoice\Service\Auth\DomainLoginException;
use MyInvoice\Service\Auth\DomainLoginService;
use MyInvoice\Service\Auth\SecurityClock;
use MyInvoice\Service\Auth\SecurityTime;
use MyInvoice\Service\Auth\SessionManager;
use MyInvoice\Service\Tenant\ClientRoutePolicy;
use MyInvoice\Service\Tenant\HostnameNormalizer;
use MyInvoice\Service\Tenant\TenantDomainFeature;
use MyInvoice\Service\Tenant\TenantDomainContext;
use MyInvoice\Service\Tenant\TenantDomainResolver;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DomainLoginMembershipTest extends TestCase
{
    private const USER_ID = 41;
    private const SUPPLIER_ID = 23;
    private const DOMAIN_ID = 17;
    private const HOSTNAME = 'portal.example.test';

    public function testExistingCanonicalSessionCannotAuthorizeDomainLogin(): void
    {
        $token = str_repeat('r', 43);
        $state = str_repeat('s', 43);
        $row = $this->requestRow($token, $state);
        $row['authorization_code_hash'] = null;
        $pdo = $this->pdoForStatements(
            $this->statementReturning($row), null, $this->statementWithRowCount(1),
        );
        $memberships = $this->createStub(UserSupplierRepository::class);
        $this->expectException(DomainLoginException::class);
        $this->expectExceptionMessage('nové přihlášení');
        $this->service($pdo, $memberships, true, false)->authorize(
            $this->canonicalRequest()->withoutAttribute(DomainLoginSessionMiddleware::ATTR_VERIFIED_FLOW),
            $token,
            $state,
            ['id' => self::USER_ID],
            ['auth_method' => 'passkey', 'assurance_level' => 'strong'],
        );
    }

    public function testSuperadminWithoutMembershipCanAuthorizeDomainLogin(): void
    {
        $requestToken = str_repeat('r', 43);
        $state = str_repeat('s', 43);
        $requestRow = $this->requestRow($requestToken, $state);
        $requestRow['authorization_code_hash'] = null;

        $select = $this->statementReturning($requestRow);
        $update = $this->statementWithRowCount(1);
        $pdo = $this->pdoForStatements($select, null, $update);
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::never())->method('allowedSupplierIds');

        $result = $this->service($pdo, $memberships, true)->authorize(
            $this->canonicalRequest(),
            $requestToken,
            $state,
            ['id' => self::USER_ID, 'is_superadmin' => true],
            ['auth_method' => 'passkey', 'assurance_level' => 'strong'],
        );

        self::assertStringStartsWith(
            'https://' . self::HOSTNAME . '/domain-login/callback?',
            $result['redirect_url'],
        );
    }

    public function testSuperadminWithoutMembershipCanExchangeDomainLoginCode(): void
    {
        $requestToken = str_repeat('r', 43);
        $code = str_repeat('c', 43);
        $state = str_repeat('s', 43);
        $verifier = str_repeat('v', 43);

        $select = $this->statementReturning($this->requestRow(
            $requestToken,
            $state,
            $code,
            $verifier,
        ));
        $user = $this->statementReturning(['id' => self::USER_ID]);
        $used = $this->statementWithRowCount(1);
        $pdo = $this->pdoForStatements($select, $user, $used);
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::never())->method('allowedSupplierIds');

        $result = $this->service($pdo, $memberships, true)->exchange(
            $this->customRequest(),
            $requestToken,
            $code,
            $state,
            $verifier,
            '192.0.2.15',
        );

        self::assertSame(self::USER_ID, $result['user_id']);
        self::assertSame(self::SUPPLIER_ID, $result['supplier_id']);
        self::assertSame('/portal', $result['return_path']);
    }

    public function testOrdinaryUserWithoutMembershipCannotAuthorizeDomainLogin(): void
    {
        $requestToken = str_repeat('r', 43);
        $state = str_repeat('s', 43);
        $requestRow = $this->requestRow($requestToken, $state);
        $requestRow['authorization_code_hash'] = null;

        $pdo = $this->pdoForStatements(
            $this->statementReturning($requestRow),
            null,
            $this->statementWithRowCount(1),
        );
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::once())
            ->method('allowedSupplierIds')
            ->with(self::USER_ID)
            ->willReturn([]);

        try {
            $this->service($pdo, $memberships)->authorize(
                $this->canonicalRequest(),
                $requestToken,
                $state,
                ['id' => self::USER_ID, 'is_superadmin' => false],
                ['auth_method' => 'passkey', 'assurance_level' => 'strong'],
            );
            self::fail('Běžný uživatel bez membershipu nesmí domain login autorizovat.');
        } catch (DomainLoginException $e) {
            self::assertSame('forbidden_supplier', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }
    }

    public function testOrdinaryUserWithoutMembershipCannotExchangeDomainLoginCode(): void
    {
        $requestToken = str_repeat('r', 43);
        $code = str_repeat('c', 43);
        $state = str_repeat('s', 43);
        $verifier = str_repeat('v', 43);

        $pdo = $this->pdoForStatements(
            $this->statementReturning($this->requestRow($requestToken, $state, $code, $verifier)),
            $this->statementReturning(['id' => self::USER_ID]),
            $this->statementWithRowCount(1),
        );
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::once())
            ->method('allowedSupplierIds')
            ->with(self::USER_ID)
            ->willReturn([]);

        try {
            $this->service($pdo, $memberships)->exchange(
                $this->customRequest(),
                $requestToken,
                $code,
                $state,
                $verifier,
                '192.0.2.15',
            );
            self::fail('Běžný uživatel bez membershipu nesmí získat session vlastní domény.');
        } catch (DomainLoginException $e) {
            self::assertSame('forbidden_supplier', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }
    }

    public function testValidMemberCanAuthorizeAndExchangeDomainLogin(): void
    {
        $requestToken = str_repeat('r', 43);
        $code = str_repeat('c', 43);
        $state = str_repeat('s', 43);
        $verifier = str_repeat('v', 43);
        $memberships = $this->createMock(UserSupplierRepository::class);
        $memberships->expects(self::exactly(2))
            ->method('allowedSupplierIds')
            ->with(self::USER_ID)
            ->willReturn([self::SUPPLIER_ID]);

        $authorizeRow = $this->requestRow($requestToken, $state);
        $authorizeRow['authorization_code_hash'] = null;
        $authorizePdo = $this->pdoForStatements(
            $this->statementReturning($authorizeRow),
            null,
            $this->statementWithRowCount(1),
        );
        $authorized = $this->service($authorizePdo, $memberships)->authorize(
            $this->canonicalRequest(),
            $requestToken,
            $state,
            ['id' => self::USER_ID, 'is_superadmin' => false],
            ['auth_method' => 'passkey', 'assurance_level' => 'strong'],
        );
        self::assertStringStartsWith(
            'https://' . self::HOSTNAME . '/domain-login/callback?',
            $authorized['redirect_url'],
        );

        $exchangePdo = $this->pdoForStatements(
            $this->statementReturning($this->requestRow($requestToken, $state, $code, $verifier)),
            $this->statementReturning(['id' => self::USER_ID]),
            $this->statementWithRowCount(1),
        );
        $result = $this->service($exchangePdo, $memberships)->exchange(
            $this->customRequest(),
            $requestToken,
            $code,
            $state,
            $verifier,
            '192.0.2.15',
        );

        self::assertSame(self::USER_ID, $result['user_id']);
        self::assertSame(self::SUPPLIER_ID, $result['supplier_id']);
        self::assertSame('/portal', $result['return_path']);
    }

    private function service(
        PDO $pdo,
        UserSupplierRepository $memberships,
        bool $isSuperadmin = false,
        bool $expectRoleLookup = true,
    ): DomainLoginService {
        $db = $this->createStub(Connection::class);
        $db->method('pdo')->willReturn($pdo);
        $clock = $this->createStub(SecurityClock::class);
        $clock->method('capture')->willReturn(SecurityTime::fromDateTime(
            new \DateTimeImmutable('2030-01-01T00:00:00Z'),
        ));
        $sessions = $this->createStub(SessionManager::class);
        $sessions->method('createInTransaction')->willReturn([
            'token' => str_repeat('a', 64),
            'csrf_token' => str_repeat('b', 64),
            'expires_at' => 2_000_000_000,
            'issued_at' => new \DateTimeImmutable('2030-01-01T00:00:00Z'),
        ]);
        $roles = $this->createMock(UserRoleProfile::class);
        $roles->expects($expectRoleLookup ? self::once() : self::never())
            ->method('isSuperadmin')
            ->with(self::USER_ID)
            ->willReturn($isSuperadmin);

        return new DomainLoginService(
            $db,
            new TenantDomainResolver(
                new Config([]),
                new HostnameNormalizer(),
                new SupplierDomainRepository($db, EntityCache::disabled()),
                new TenantDomainFeature(new Config(['domains' => ['enabled' => true]])),
            ),
            $memberships,
            $roles,
            new ClientRoutePolicy(),
            $clock,
            $sessions,
        );
    }

    private function pdoForStatements(
        PDOStatement $request,
        ?PDOStatement $user,
        PDOStatement $update,
    ): PDO {
        $pdo = $this->createStub(PDO::class);
        $pdo->method('beginTransaction')->willReturn(true);
        $pdo->method('commit')->willReturn(true);
        $pdo->method('rollBack')->willReturn(true);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('prepare')->willReturnCallback(
            static function (string $sql) use ($request, $user, $update): PDOStatement {
                if (str_contains($sql, 'FROM supplier_domain_login_requests')) return $request;
                if (str_contains($sql, 'SELECT u.id FROM users')) {
                    if ($user === null) throw new \LogicException('Neočekávaný user dotaz.');
                    return $user;
                }
                if (str_contains($sql, 'UPDATE supplier_domain_login_requests')) return $update;
                throw new \LogicException('Neočekávaný SQL dotaz: ' . $sql);
            },
        );
        return $pdo;
    }

    /** @param array<string,mixed>|false $row */
    private function statementReturning(array|false $row): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetch')->willReturn($row);
        return $statement;
    }

    private function statementWithRowCount(int $rowCount): PDOStatement
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('rowCount')->willReturn($rowCount);
        return $statement;
    }

    /** @return array<string,mixed> */
    private function requestRow(
        string $requestToken,
        string $state,
        ?string $code = null,
        ?string $verifier = null,
    ): array {
        return [
            'id' => 29,
            'request_token_hash' => hash('sha256', $requestToken),
            'supplier_domain_id' => self::DOMAIN_ID,
            'supplier_id' => self::SUPPLIER_ID,
            'target_hostname' => self::HOSTNAME,
            'state_hash' => hash('sha256', $state),
            'pkce_challenge' => $verifier !== null ? self::pkceChallenge($verifier) : str_repeat('p', 43),
            'return_path' => '/portal',
            'expires_at' => '2099-01-01 00:00:00.000000',
            'used_at' => null,
            'authorized_by' => $code !== null ? self::USER_ID : null,
            'authorization_code_hash' => $code !== null ? hash('sha256', $code) : null,
            'code_expires_at' => $code !== null ? '2099-01-01 00:00:00.000000' : null,
            'auth_method' => 'passkey',
            'assurance_level' => 'strong',
            'mfa_verified_at' => '2030-01-01 00:00:00.000000',
            'auth_credential_id' => null,
            'domain_status' => 'active',
            'domain_purpose' => 'portal',
        ];
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
        ))->withAttribute(DomainLoginSessionMiddleware::ATTR_VERIFIED_FLOW, [
            'request_token_hash' => hash('sha256', str_repeat('r', 43)),
            'state_hash' => hash('sha256', str_repeat('s', 43)),
        ]);
    }

    private function customRequest(): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            'POST',
            'https://' . self::HOSTNAME . '/api/auth/domain-login/exchange',
        )->withAttribute(TenantDomainMiddleware::ATTR_CONTEXT, new TenantDomainContext(
            TenantDomainContext::CUSTOM,
            self::HOSTNAME,
            'https://' . self::HOSTNAME,
            self::DOMAIN_ID,
            self::SUPPLIER_ID,
            'portal',
            'active',
        ));
    }

    private static function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
