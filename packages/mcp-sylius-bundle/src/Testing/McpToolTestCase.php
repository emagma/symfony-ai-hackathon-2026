<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Testing;

use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\Redaction\SensitiveDataRedactor;
use Emagma\McpCoreBundle\Testing\ElicitationTestTrait;
use Emagma\McpCoreBundle\Testing\SinkWriteJournal;
use Emagma\McpCoreBundle\Testing\WriteSinkInterface;
use Emagma\McpSyliusBundle\AdminApi\Adapter\DefaultLayoutAdapter;
use Emagma\McpSyliusBundle\AdminApi\Adapter\SyliusApiAdapterInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\SyliusSensitiveKeys;
use Emagma\McpSyliusBundle\File\FileInput;
use Emagma\McpSyliusBundle\File\RemoteFileDownloader;
use Emagma\McpSyliusBundle\SyliusSources;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Harness of the tool unit tests: mocked Admin API client, journal over a mocked sink, no I/O.
 */
abstract class McpToolTestCase extends TestCase
{
    use ElicitationTestTrait;

    /**
     * The result goes through JSON: assertions bear on what an MCP client receives, not on the DTO.
     *
     * @return array<string, mixed>
     */
    protected function call(object $tool, mixed ...$arguments): array
    {
        self::assertIsCallable($tool);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            json_encode($tool(...$arguments), \JSON_THROW_ON_ERROR),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function wire(mixed $result): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($result, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return AdminApiClientInterface&MockObject
     */
    protected function apiClient(): AdminApiClientInterface
    {
        return $this->createMock(AdminApiClientInterface::class);
    }

    protected function adapter(): SyliusApiAdapterInterface
    {
        return new DefaultLayoutAdapter('http://shop.test/api/v2/admin/');
    }

    protected function grants(string ...$grants): GrantsProviderInterface
    {
        $provider = $this->createStub(GrantsProviderInterface::class);
        $provider->method('grants')->willReturn(Grants::fromStrings(array_values($grants)));

        return $provider;
    }

    /**
     * `$download` is what the client's file store serves for an attached file; without it, no download is expected.
     */
    protected function fileInput(?string $download = null): FileInput
    {
        $http = null === $download
            ? new MockHttpClient(static fn (): MockResponse => self::fail('no file was expected to be downloaded'))
            : new MockHttpClient(new MockResponse($download));

        return new FileInput(new RemoteFileDownloader($http, ['oaiusercontent.com']));
    }

    protected function audit(): WriteJournalInterface
    {
        return new SinkWriteJournal($this->createStub(WriteSinkInterface::class), $this->redactor());
    }

    /**
     * The journal and the mocked sink it writes to, to assert the write a tool recorded.
     *
     * @return array{WriteJournalInterface, WriteSinkInterface&MockObject}
     */
    protected function auditSpy(): array
    {
        $sink = $this->createMock(WriteSinkInterface::class);

        return [new SinkWriteJournal($sink, $this->redactor()), $sink];
    }

    protected function redactor(): SensitiveDataRedactor
    {
        return new SensitiveDataRedactor(providers: [new SyliusSensitiveKeys(SyliusSources::outputs()->personalKeys())]);
    }

    /**
     * @param list<array<string, mixed>> $members
     *
     * @return array<string, mixed>
     */
    protected function collection(array $members, ?int $total = null): array
    {
        return [
            '@type' => 'hydra:Collection',
            'member' => $members,
            'totalItems' => $total ?? \count($members),
        ];
    }

    /**
     * @param list<array{propertyPath?: string, message?: string}> $violations
     */
    protected function clientException(int $status, array $violations = []): ClientException
    {
        $payload = [] === $violations ? [] : ['violations' => $violations];
        $body = [] === $payload ? '' : (string) json_encode($payload);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getInfo')->willReturnCallback(
            static fn (?string $type = null) => match ($type) {
                'http_code' => $status,
                'url' => 'https://test/',
                'response_headers' => [\sprintf('HTTP/1.1 %d', $status)],
                default => null,
            },
        );
        $response->method('getContent')->willReturn($body);
        $response->method('toArray')->willReturn($payload);

        return new ClientException($response);
    }
}
