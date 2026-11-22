<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Emagma\McpAuthKeycloakBundle\Testing\FakeKeycloakHttpClient;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** A test states what the scenario expects, not what works today: a red test is a scenario that does not pass yet. */
abstract class QaScenarioTestCase extends WebTestCase
{
    protected const string CHANNEL = 'FASHION_WEB';

    private const string CLIENT_NAME = 'sylius-assistant-qa';

    private const string AUDIT_DATABASE = 'sqlite:///%kernel.project_dir%/var/qa-audit.db';

    private KernelBrowser $client;

    /** @var array<string, string> */
    private array $headers = [];

    private int $requestId = 1;

    /** @var array{server: mixed, env: mixed} */
    private array $previousDatabaseUrl;

    protected function setUp(): void
    {
        $shop = $_SERVER['SYLIUS_API_URL'] ?? $_ENV['SYLIUS_API_URL'] ?? '';
        if (!\is_string($shop) || str_contains($shop, 'shop.test')) {
            self::fail('The QA scenarios need the shop of the stack: run them with `castor mcp:qa`.');
        }

        $this->previousDatabaseUrl = ['server' => $_SERVER['DATABASE_URL'] ?? null, 'env' => $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = self::AUDIT_DATABASE;

        $this->client = self::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $schema = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $token = FakeKeycloakHttpClient::token('qa@sylius-assistant.test', ['mcp:all:all:write', 'mcp:all:all:delete', 'mcp:core:pii:reveal']);
        $this->headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json, text/event-stream'];
        $this->client->jsonRequest('POST', '/_mcp', [
            'jsonrpc' => '2.0',
            'id' => $this->requestId++,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => self::CLIENT_NAME, 'version' => '1'],
            ],
        ], $this->headers);
        self::assertResponseIsSuccessful();
        $this->headers['HTTP_MCP_SESSION_ID'] = (string) $this->client->getResponse()->headers->get('Mcp-Session-Id');
        $this->client->jsonRequest('POST', '/_mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $this->headers);
    }

    protected function tearDown(): void
    {
        foreach (['server' => &$_SERVER, 'env' => &$_ENV] as $key => &$bag) {
            if (null === $this->previousDatabaseUrl[$key]) {
                unset($bag['DATABASE_URL']);
            } else {
                $bag['DATABASE_URL'] = $this->previousDatabaseUrl[$key];
            }
        }
        unset($bag);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    protected function call(string $tool, array $arguments = []): array
    {
        $this->client->jsonRequest('POST', '/_mcp', [
            'jsonrpc' => '2.0',
            'id' => $this->requestId++,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => (object) $arguments],
        ], $this->headers);
        self::assertResponseIsSuccessful();

        /** @var array{result?: array{content?: list<array{text?: string}>, structuredContent?: array<string, mixed>, isError?: bool}, error?: array{message?: string}} $body */
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        if (isset($body['error'])) {
            self::fail(sprintf('%s: %s', $tool, $body['error']['message'] ?? json_encode($body['error'])));
        }
        $text = $body['result']['content'][0]['text'] ?? '';
        if (true === ($body['result']['isError'] ?? false)) {
            self::fail(sprintf('%s: %s', $tool, $text));
        }
        if (isset($body['result']['structuredContent'])) {
            return $body['result']['structuredContent'];
        }
        /** @var array<string, mixed> $result */
        $result = json_decode($text, true, flags: \JSON_THROW_ON_ERROR);

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<array<string, mixed>>
     */
    protected function searchAll(string $tool, array $arguments = []): array
    {
        $items = [];
        $page = 1;
        do {
            $result = $this->call($tool, $arguments + ['page' => $page, 'itemsPerPage' => 50]);
            /** @var list<array<string, mixed>> $pageItems */
            $pageItems = $result['items'] ?? [];
            array_push($items, ...$pageItems);
            ++$page;
        } while (true === ($result['hasMore'] ?? false));

        return $items;
    }

    /**
     * @param array<string, mixed> $result
     */
    protected static function assertBatchApplied(array $result): void
    {
        self::assertSame(0, $result['failed'] ?? null, 'failed items: ' . json_encode(array_filter(
            \is_array($result['results'] ?? null) ? $result['results'] : [$result],
            static fn (mixed $item): bool => \is_array($item) && false === ($item['ok'] ?? null),
        ), \JSON_PRETTY_PRINT));
    }
}
