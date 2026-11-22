<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Functional;

use Emagma\McpAuthKeycloakBundle\Testing\FakeKeycloakHttpClient;
use Emagma\McpCoreBundle\Authorization\Deprecated;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensionGate;
use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensions;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use Emagma\McpSyliusV2Bundle\Sylius2Adapter;
use Emagma\SyliusMcpServer\ServerSources;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Booting the real kernel compiles the discovery of every #[McpTool]: a green boot guards their wiring. */
final class McpEndpointTest extends WebTestCase
{
    // The test client's host, not DEFAULT_URI: the pointer follows the request.
    private const string METADATA_POINTER = 'resource_metadata="http://localhost/.well-known/oauth-protected-resource"';

    /** `media` is singular and plural alike (the CMS plugin's word): a trailing `s` cannot be required of it. */
    private const string BATCH_TOOL = '/^(search|upsert)_|^(get|delete)_(\w+s|\w+_media)$/';

    public function testAnAnonymousCallIsChallengedWithTheMetadataPointer(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/_mcp', $this->initialize());

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer ' . self::METADATA_POINTER);
    }

    public function testABadTokenIsChallengedWithTheMetadataPointerToo(): void
    {
        $client = self::createClient();
        $client->jsonRequest('POST', '/_mcp', $this->initialize(), ['HTTP_AUTHORIZATION' => 'Bearer not.a.token']);

        self::assertResponseStatusCodeSame(401);
        $header = (string) $client->getResponse()->headers->get('WWW-Authenticate');
        self::assertStringContainsString('error="invalid_token"', $header);
        self::assertStringContainsString(self::METADATA_POINTER, $header);
    }

    public function testTheProtectedResourceMetadataIsPublicAndNamesKeycloak(): void
    {
        $client = self::createClient();
        $client->request('GET', '/.well-known/oauth-protected-resource');

        self::assertResponseIsSuccessful();
        $document = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('http://localhost/_mcp', $document['resource']);
        // A real MCP_AUTH_ISSUER_URL (compose) wins over .env.test.
        $issuer = $_SERVER['MCP_AUTH_ISSUER_URL'] ?? $_ENV['MCP_AUTH_ISSUER_URL'];
        self::assertSame([$issuer], $document['authorization_servers']);
    }

    public function testAValidBearerSeesExactlyTheToolsItsRealmRolesGrant(): void
    {
        $client = self::createClient();
        $token = FakeKeycloakHttpClient::token('reader@keycloak.test', ['mcp:sylius:product:read', 'offline_access']);

        $tools = $this->listTools($client, $token);

        self::assertSame(['get_products', 'search_products'], $tools);
    }

    public function testInitializeStatesTheSharedConventionsAsInstructions(): void
    {
        $client = self::createClient();
        $token = FakeKeycloakHttpClient::token('reader@keycloak.test', ['mcp:sylius:product:read', 'offline_access']);
        $client->jsonRequest('POST', '/_mcp', $this->initialize(), [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ]);
        self::assertResponseIsSuccessful();

        /** @var array{result: array{instructions?: string}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $instructions = $body['result']['instructions'] ?? '';

        foreach (['elicitation', 'confirm: true', 'found: false', 'itemsPerPage', 'minor units', 'untrustedContent', 'revert_mcp_edit', 'ok: true|false', 'upstream_error', 'No tool sends a message you write'] as $needle) {
            self::assertStringContainsString($needle, $instructions);
        }
        $locale = $_SERVER['MCP_DEFAULT_LOCALE'] ?? $_ENV['MCP_DEFAULT_LOCALE'];
        self::assertStringContainsString('default `' . $locale . '`', $instructions);
        self::assertStringNotContainsString('%env(', $instructions);
    }

    /** Every bundle adds its block to the instructions: the backend's conventions first, then the guidelines' intents. */
    public function testInitializeTellsTheModelToAskForTheGuidelinesAfterTheConventions(): void
    {
        $client = self::createClient();
        $token = FakeKeycloakHttpClient::token('reader@keycloak.test', ['mcp:sylius:product:read', 'offline_access']);
        $client->jsonRequest('POST', '/_mcp', $this->initialize(), [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
        ]);

        /** @var array{result: array{instructions?: string}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        $instructions = $body['result']['instructions'] ?? '';

        self::assertStringContainsString('you MUST call get_guidelines(intents: [...])', $instructions);
        self::assertStringContainsString('- product_description: Product written content', $instructions);
        self::assertGreaterThan(strpos($instructions, 'Conventions shared by every tool'), strpos($instructions, '## Writing for the business'));
    }

    /** A 2026-07-28 request carries no session, and the write confirmation reads the client name from it. */
    public function testAModernEraRequestIsRefused(): void
    {
        $client = self::createClient();
        $token = FakeKeycloakHttpClient::token('reader@keycloak.test', ['mcp:sylius:product:read', 'offline_access']);
        $client->jsonRequest('POST', '/_mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientInfo' => ['name' => 'phpunit', 'version' => '1'],
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
            'HTTP_MCP_METHOD' => 'tools/list',
        ]);

        /** @var array{result?: mixed, error?: array{code: int, message: string}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertResponseStatusCodeSame(400);
        self::assertArrayNotHasKey('result', $body);
        // -32022: unsupported protocol version.
        self::assertSame(-32022, $body['error']['code'] ?? null);
    }

    /** Through the real chain, not an anonymous class: a #[Deprecated] whose import did not land would match nothing. */
    public function testDeprecatedToolsAreHiddenFromTheListOverTheWire(): void
    {
        $client = self::createClient();
        $token = FakeKeycloakHttpClient::token('taxes@keycloak.test', [
            'mcp:sylius:tax_category:read', 'mcp:sylius:tax_category:write', 'mcp:sylius:tax_category:delete', 'offline_access',
        ]);

        self::assertSame(
            ['delete_tax_categories', 'get_tax_categories', 'search_tax_categories', 'upsert_tax_categories'],
            $this->listTools($client, $token),
        );
    }

    public function testNoDeprecatedToolIsListedAndEveryBatchToolIs(): void
    {
        $client = self::createClient();
        $token = FakeKeycloakHttpClient::token('admin@keycloak.test', ['mcp:all:all:read', 'mcp:all:all:write', 'mcp:all:all:delete', 'offline_access']);
        $listed = $this->listTools($client, $token);

        $extensions = $this->shopExtensions();

        $deprecated = [];
        $disabled = [];
        $batch = [];
        foreach ($this->toolInvokeMethods() as $name => $method) {
            $class = $method->getDeclaringClass();
            $extension = ShopExtensionGate::requiredBy($class->getName());
            if ([] !== $class->getAttributes(Deprecated::class)) {
                $deprecated[] = $name;
            } elseif (null !== $extension && !$extensions->isActive($extension)) {
                $disabled[] = $name;
            } elseif (1 === preg_match(self::BATCH_TOOL, $name)) {
                $batch[] = $name;
            }
        }

        self::assertSame([], $deprecated, 'phase 5 removed every deprecated tool; a new deprecation is a deliberate act');
        self::assertSame([], array_values(array_diff($batch, $listed)), 'batch tools missing from the list');
        self::assertSame([], array_values(array_intersect($disabled, $listed)), 'tools of an inactive shop extension are listed');
    }

    /**
     * @param \Closure(): void $test
     */
    private function withEnv(string $name, string $value, \Closure $test): void
    {
        $saved = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];

        try {
            self::ensureKernelShutdown();
            $this->setEnv($name, $value);
            $test();
        } finally {
            self::ensureKernelShutdown();
            [$env, $server, $real] = $saved;
            if (null === $env) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            if (null === $server) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
            putenv(false === $real ? $name : $name . '=' . $real);
        }
    }

    public function testTheShopExtensionsAreReadAtRuntimeNotCompiledIn(): void
    {
        $saved = [$_ENV['MCP_SHOP_EXTENSIONS'] ?? null, $_SERVER['MCP_SHOP_EXTENSIONS'] ?? null, getenv('MCP_SHOP_EXTENSIONS')];

        try {
            foreach (['', ' , '] as $value) {
                $this->setShopExtensionsEnv($value);
                self::ensureKernelShutdown();
                self::bootKernel();

                $service = self::getContainer()->get(ShopExtensions::class);
                self::assertInstanceOf(ShopExtensions::class, $service);
                self::assertFalse($service->isActive('typo'), 'MCP_SHOP_EXTENSIONS="' . $value . '"');
            }

            $this->setShopExtensionsEnv('typo');
            self::ensureKernelShutdown();
            self::bootKernel();

            try {
                self::getContainer()->get(ShopExtensions::class);
                self::fail('an unknown shop extension must stop the server');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('"typo"', $e->getMessage());
            }
        } finally {
            self::ensureKernelShutdown();
            [$env, $server, $real] = $saved;
            if (null === $env) {
                unset($_ENV['MCP_SHOP_EXTENSIONS']);
            } else {
                $_ENV['MCP_SHOP_EXTENSIONS'] = $env;
            }
            if (null === $server) {
                unset($_SERVER['MCP_SHOP_EXTENSIONS']);
            } else {
                $_SERVER['MCP_SHOP_EXTENSIONS'] = $server;
            }
            putenv(false === $real ? 'MCP_SHOP_EXTENSIONS' : 'MCP_SHOP_EXTENSIONS=' . $real);
        }
    }

    private function setShopExtensionsEnv(string $value): void
    {
        $this->setEnv('MCP_SHOP_EXTENSIONS', $value);
    }

    private function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }

    public function testAToolGatedByTheSyliusVersionIsListedOnALineThatHasIt(): void
    {
        $token = FakeKeycloakHttpClient::token('ops@keycloak.test', ['mcp:sylius:payment:write', 'offline_access']);

        $this->withEnv('MCP_SYLIUS_VERSION', '2.0', function () use ($token): void {
            self::assertContains('refund_payment', $this->listTools(self::createClient(), $token));
        });
    }

    public function testTheSyliusVersionIsReadAtRuntimeNotCompiledIn(): void
    {
        foreach (['2.0' => ['2.0', Sylius2Adapter::class], ' 2.2.9 ' => ['2.2.9', Sylius2Adapter::class]] as $value => [$expected, $adapter]) {
            $this->withEnv('MCP_SYLIUS_VERSION', (string) $value, function () use ($expected, $adapter, $value): void {
                self::bootKernel();
                $service = self::getContainer()->get(SyliusVersion::class);
                self::assertInstanceOf(SyliusVersion::class, $service);
                self::assertSame($expected, (string) $service, 'MCP_SYLIUS_VERSION="' . $value . '"');
                self::assertInstanceOf($adapter, self::getContainer()->get(SyliusApiAdapterInterface::class));
            });
        }

        foreach (['2.4', '1.14'] as $unsupported) {
            $this->withEnv('MCP_SYLIUS_VERSION', $unsupported, function () use ($unsupported): void {
                self::bootKernel();

                try {
                    self::getContainer()->get(SyliusVersion::class);
                    self::fail('an unsupported Sylius version must stop the server');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('"' . $unsupported . '"', $e->getMessage());
                }
            });
        }
    }

    public function testToolsListCarriesTheAnnotationsOverTheWire(): void
    {
        $client = self::createClient();
        $token = FakeKeycloakHttpClient::token('ops@keycloak.test', [
            'mcp:sylius:product:read', 'mcp:sylius:product:delete', 'mcp:sylius:payment:write', 'offline_access',
        ]);

        $annotations = [];
        foreach ($this->listToolsRaw($client, $token) as $tool) {
            $annotations[$tool['name']] = $tool['annotations'] ?? null;
        }

        self::assertSame(['readOnlyHint' => true, 'idempotentHint' => true, 'openWorldHint' => false], $annotations['get_products']);
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false], $annotations['delete_products']);
        self::assertSame(
            ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            $annotations['refund_payment'],
        );
        self::assertNotContains(null, $annotations, 'every listed tool must carry annotations');
    }

    public function testABearerWithNoMcpRoleIsAuthenticatedButSeesNothing(): void
    {
        $client = self::createClient();
        // sylius-admin is an admin-UI role: on its own it must unlock no tool.
        $token = FakeKeycloakHttpClient::token('admin@keycloak.test', ['sylius-admin']);

        self::assertSame([], $this->listTools($client, $token));
    }

    public function testBatchItemsAreTypedObjectsWithTheirGuidanceOnEachProperty(): void
    {
        $schema = $this->schemaForTool('upsert_tax_categories');

        $items = $schema['properties']['items'];
        self::assertSame('array', $items['type']);
        self::assertSame(1, $items['minItems']);
        self::assertSame('object', $items['items']['type']);
        self::assertSame(['code'], $items['items']['required']);
        self::assertFalse($items['items']['additionalProperties']);
        self::assertArrayHasKey('description', $items['items']['properties']['name']);
        self::assertSame(['create', 'update', 'upsert'], $schema['properties']['mode']['enum']);
        self::assertContains('items', $schema['required']);
        self::assertNotContains('mode', $schema['required']);
    }

    public function testProductOptionValuesSchemaIsAnObjectNotAnArray(): void
    {
        $item = $this->schemaForTool('upsert_product_options')['properties']['items']['items'];

        self::assertSame('object', $item['properties']['values']['type']);
        self::assertSame(['code'], $item['required']);
        self::assertFalse($item['additionalProperties']);
    }

    /** `completed` is a checkout and a payment state, never an order's. */
    public function testAnOrderStateTheShopDoesNotHaveIsRefusedNotSearched(): void
    {
        $client = self::createClient();
        $token = FakeKeycloakHttpClient::token('sales@keycloak.test', ['mcp:sylius:order:read']);

        $body = $this->callTool($client, $token, 'search_orders', ['state' => ['completed'], 'paymentState' => ['paid'], 'shippingState' => ['ready']]);

        self::assertArrayNotHasKey('error', $body, 'an argument mistake is the model\'s to fix, not a protocol failure');
        self::assertTrue($body['result']['isError'] ?? false, 'an unknown order state must not be searched');
        $text = $body['result']['content'][0]['text'] ?? '';
        self::assertStringContainsString("Property '/state/0'", $text);
        self::assertStringContainsString('"fulfilled"', $text, 'the allowed values are named');
        self::assertStringContainsString('call the tool again', $text);

        $schema = $this->listedInputSchema($client, $token, 'search_orders');
        self::assertSame(['new', 'fulfilled', 'cancelled'], $schema['properties']['state']['items']['enum'] ?? null);
        self::assertContains('paid', $schema['properties']['paymentState']['items']['enum'] ?? []);
    }

    public function testTheUploadToolsDeclareTheAttachedFileArgumentToChatGpt(): void
    {
        $client = self::createClient();
        $token = FakeKeycloakHttpClient::token('catalog@keycloak.test', ['mcp:sylius:product_image:write']);

        $tools = array_column($this->listToolsRaw($client, $token), null, 'name');
        self::assertSame(['openai/fileParams' => ['file']], $tools['create_product_image']['_meta'] ?? null);

        $schema = $this->listedInputSchema($client, $token, 'create_product_image');
        $file = $schema['properties']['file'];
        self::assertSame(['object', 'null'], $file['type']);
        self::assertSame(['download_url', 'file_id', 'mime_type', 'file_name'], array_keys($file['properties']));
        self::assertSame(['download_url', 'file_id'], $file['required']);
        self::assertNotContains('base64Content', $schema['required'] ?? [], 'the file comes either way');
        self::assertNotContains('filename', $schema['required'] ?? []);

        $body = $this->callTool($client, $token, 'create_product_image', ['code' => 'MUG', 'file' => ['download_url' => 'https://files.oaiusercontent.com/f']]);
        self::assertTrue($body['result']['isError'] ?? false);
        self::assertStringContainsString('file_id', $body['result']['content'][0]['text'] ?? '');
    }

    public function testEveryMcpToolGeneratesAValidInputSchema(): void
    {
        $generator = new SchemaGenerator(new DocBlockParser());

        $tools = $this->toolInvokeMethods();
        self::assertGreaterThan(100, \count($tools), 'tool discovery looks broken');

        foreach ($tools as $name => $method) {
            $schema = $generator->generate($method);
            self::assertArrayHasKey('properties', $schema, "schema for {$name} has no properties");
        }
    }

    /**
     * @return list<string> the tool names, sorted
     */
    private function listTools(KernelBrowser $client, string $token): array
    {
        $names = array_column($this->listToolsRaw($client, $token), 'name');
        sort($names);

        return $names;
    }

    /**
     * @return list<array{name: string, annotations?: array<string, bool|string>, _meta?: array<string, mixed>}> the tools as the client receives them
     */
    private function listToolsRaw(KernelBrowser $client, string $token): array
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json, text/event-stream'];

        $client->jsonRequest('POST', '/_mcp', $this->initialize(), $headers);
        self::assertResponseIsSuccessful();
        $session = (string) $client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotSame('', $session, 'initialize returned no session');
        $headers['HTTP_MCP_SESSION_ID'] = $session;

        $client->jsonRequest('POST', '/_mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers);
        $client->jsonRequest('POST', '/_mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $headers);
        self::assertResponseIsSuccessful();

        /** @var array{result: array{tools: list<array{name: string, annotations?: array<string, bool|string>, _meta?: array<string, mixed>}>}} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $body['result']['tools'];
    }

    /**
     * @return array{properties: array<string, array<string, mixed>>}
     */
    private function listedInputSchema(KernelBrowser $client, string $token, string $toolName): array
    {
        foreach ($this->listToolsRaw($client, $token) as $tool) {
            if ($toolName === $tool['name']) {
                /** @var array{properties: array<string, array<string, mixed>>} $schema */
                $schema = $tool['inputSchema'] ?? ['properties' => []];

                return $schema;
            }
        }
        self::fail($toolName . ' is not listed');
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{result?: array{content?: list<array{text?: string}>}, error?: array<string, mixed>}
     */
    private function callTool(KernelBrowser $client, string $token, string $name, array $arguments): array
    {
        $headers = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'HTTP_ACCEPT' => 'application/json, text/event-stream'];

        $client->jsonRequest('POST', '/_mcp', $this->initialize(), $headers);
        self::assertResponseIsSuccessful();
        $headers['HTTP_MCP_SESSION_ID'] = (string) $client->getResponse()->headers->get('Mcp-Session-Id');

        $client->jsonRequest('POST', '/_mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $headers);
        $client->jsonRequest('POST', '/_mcp', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ], $headers);

        /** @var array{result?: array{content?: list<array{text?: string}>}, error?: array<string, mixed>} $body */
        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForTool(string $toolName): array
    {
        $method = $this->toolInvokeMethods()[$toolName] ?? null;
        self::assertNotNull($method, "tool {$toolName} not discovered");

        return (new SchemaGenerator(new DocBlockParser()))->generate($method);
    }

    /**
     * @return array<string, \ReflectionMethod>
     */
    private function toolInvokeMethods(): array
    {
        $methods = [];

        foreach (ServerSources::tools()->classes() as $class) {
            $reflection = new \ReflectionClass($class);
            $attributes = $reflection->getAttributes(McpTool::class);
            if ([] === $attributes || !$reflection->hasMethod('__invoke')) {
                continue;
            }
            $methods[$attributes[0]->newInstance()->name] = $reflection->getMethod('__invoke');
        }

        return $methods;
    }

    private function shopExtensions(): ShopExtensions
    {
        $value = $_ENV['MCP_SHOP_EXTENSIONS'] ?? $_SERVER['MCP_SHOP_EXTENSIONS'] ?? '';

        return new ShopExtensions(\is_string($value) ? $value : '');
    }
}
