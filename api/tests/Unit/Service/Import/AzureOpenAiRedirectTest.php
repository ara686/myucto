<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use MyInvoice\Service\Import\AzureOpenAiClient;
use MyInvoice\Tests\Support\FakeAiProviderHttpClientFactory;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Azure endpoint zadává admin firmy a `*.azure-api.net` si může postavit kdokoli
 * (APIM s redirect politikou). Klient proto přesměrování nenásleduje: 30x by
 * jinak poslalo klíč i tělo dotazu do interní sítě provozovatele a odpověď
 * vrátilo zpátky volajícímu.
 */
final class AzureOpenAiRedirectTest extends TestCase
{
    public function testAzureClientDoesNotFollowRedirectToInternalHost(): void
    {
        $history = [];
        $client = $this->azureClient('https://tenant.azure-api.net', [
            new Response(302, ['Location' => 'http://canary.internal.test/canary-200']),
            new Response(200, [], (string) json_encode(['model' => 'INTERNAL-BODY'])),
        ], $history);

        $result = $client->testConnection(1);

        self::assertFalse($result['ok']);
        self::assertSame('HTTP 302', $result['error']);
        self::assertCount(1, $history, 'Na cíl přesměrování nesmí odejít žádný požadavek.');
        self::assertSame('tenant.azure-api.net', $history[0]['request']->getUri()->getHost());
    }

    public function testStoredEndpointOutsideAzureIsRefusedAtRequestTime(): void
    {
        $history = [];
        $client = $this->azureClient('https://169.254.169.254', [new Response(200, [], '{}')], $history);

        $result = $client->testConnection(1);

        self::assertFalse($result['ok']);
        self::assertCount(0, $history);
    }

    public function testEndpointRuleIsShared(): void
    {
        self::assertNull(AzureOpenAiClient::endpointError('https://x.openai.azure.com'));
        self::assertNotNull(AzureOpenAiClient::endpointError('http://x.openai.azure.com'));
        self::assertNotNull(AzureOpenAiClient::endpointError('https://x.azure-api.net.attacker.test'));
    }

    public function testAiProviderHttpClientDoesNotFollowRedirect(): void
    {
        $history = [];
        $client = FakeAiProviderHttpClientFactory::create($this, 'openai', [
            new Response(307, ['Location' => 'http://canary.internal.test/post']),
            new Response(200, [], '{}'),
        ], $history);

        $result = $client->completeJson(1, 'system', 'user', ['type' => 'object']);

        self::assertFalse($result['ok']);
        self::assertCount(1, $history);
    }

    /**
     * @param list<Response> $queue
     * @param array<int,array<string,mixed>> $history
     */
    private function azureClient(string $endpoint, array $queue, array &$history): AzureOpenAiClient
    {
        // Klient bez explicitního allow_redirects = výchozí chování Guzzle (následuje).
        $stack = HandlerStack::create(new MockHandler($queue));
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack, 'http_errors' => false]);

        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($endpoint) {
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            $stmt->method('fetch')->willReturn(str_contains($sql, 'azure_openai_api_key_enc') ? [
                'azure_openai_api_key_enc' => 'ENC',
                'azure_openai_endpoint' => $endpoint,
                'azure_openai_deployment' => 'gpt-5',
                'azure_openai_api_version' => '2024-10-21',
            ] : []);
            return $stmt;
        });
        $conn = $this->createStub(Connection::class);
        $conn->method('pdo')->willReturn($pdo);
        $crypto = $this->createStub(SecretEncryption::class);
        $crypto->method('decrypt')->willReturn('synthetic-azure-key');

        return new AzureOpenAiClient($conn, $crypto, new NullLogger(), $http);
    }
}
