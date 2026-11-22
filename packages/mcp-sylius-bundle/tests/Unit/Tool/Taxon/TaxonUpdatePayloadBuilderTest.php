<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Taxon;

use Emagma\McpSyliusBundle\AdminApi\Adapter\DefaultLayoutAdapter;
use Emagma\McpSyliusBundle\Tool\Taxon\TaxonUpdatePayloadBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(TaxonUpdatePayloadBuilder::class)]
final class TaxonUpdatePayloadBuilderTest extends TestCase
{
    private const string LOCALE = 'en_US';

    private TaxonUpdatePayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TaxonUpdatePayloadBuilder(new DefaultLayoutAdapter('http://shop.test/api/v2/admin/'));
    }

    public function testComputeDiffDetectsNameChange(): void
    {
        $current = [
            'enabled' => true,
            'translations' => [self::LOCALE => ['name' => 'Cats', 'slug' => 'cats']],
        ];
        $requested = ['name' => 'Felines', 'slug' => null, 'description' => null, 'enabled' => null, 'position' => null];

        $diff = $this->builder->computeDiff($current, self::LOCALE, $requested);

        self::assertArrayHasKey(self::LOCALE . '.name', $diff);
        self::assertSame('Cats', $diff[self::LOCALE . '.name']['before']);
        self::assertSame('Felines', $diff[self::LOCALE . '.name']['after']);
        self::assertArrayNotHasKey(self::LOCALE . '.slug', $diff);
    }

    public function testComputeDiffReturnsEmptyWhenNothingChanged(): void
    {
        $current = [
            'enabled' => true,
            'translations' => [self::LOCALE => ['name' => 'Cats', 'slug' => 'cats']],
        ];
        $requested = ['name' => 'Cats', 'slug' => 'cats', 'description' => null, 'enabled' => null, 'position' => null];

        $diff = $this->builder->computeDiff($current, self::LOCALE, $requested);

        self::assertSame([], $diff);
    }

    public function testComputeDiffDetectsEnabledChange(): void
    {
        $current = ['enabled' => true, 'translations' => []];
        $requested = ['name' => null, 'slug' => null, 'description' => null, 'enabled' => false, 'position' => null];

        $diff = $this->builder->computeDiff($current, self::LOCALE, $requested);

        self::assertArrayHasKey('enabled', $diff);
        self::assertTrue($diff['enabled']['before']);
        self::assertFalse($diff['enabled']['after']);
    }

    public function testComputeDiffDetectsPositionChange(): void
    {
        $current = ['enabled' => true, 'position' => 3, 'translations' => []];
        $requested = ['name' => null, 'slug' => null, 'description' => null, 'enabled' => null, 'position' => 7];

        $diff = $this->builder->computeDiff($current, self::LOCALE, $requested);

        self::assertArrayHasKey('position', $diff);
        self::assertSame(3, $diff['position']['before']);
        self::assertSame(7, $diff['position']['after']);
    }

    public function testBuildPutBodyMutatesNameAndPreservesTranslationAtId(): void
    {
        $current = [
            '@id' => '/api/v2/admin/taxons/cats',
            'code' => 'cats',
            'enabled' => true,
            'translations' => [
                self::LOCALE => [
                    '@id' => '/api/v2/admin/taxon-translations/5',
                    'name' => 'Cats',
                    'slug' => 'cats',
                    'locale' => self::LOCALE,
                ],
            ],
        ];
        $requested = ['name' => 'Felines', 'slug' => null, 'description' => null, 'enabled' => null, 'position' => null];

        $body = $this->builder->buildPutBody($current, self::LOCALE, $requested);

        self::assertSame('/api/v2/admin/taxons/cats', $body['@id']);
        self::assertSame('Felines', $body['translations'][self::LOCALE]['name']);
        self::assertSame('/api/v2/admin/taxon-translations/5', $body['translations'][self::LOCALE]['@id']);
        self::assertSame('cats', $body['translations'][self::LOCALE]['slug']);
    }

    public function testBuildPutBodyUpdatesEnabledAndPosition(): void
    {
        $current = ['code' => 'cats', 'enabled' => true, 'position' => 2, 'translations' => []];
        $requested = ['name' => null, 'slug' => null, 'description' => null, 'enabled' => false, 'position' => 10];

        $body = $this->builder->buildPutBody($current, self::LOCALE, $requested);

        self::assertFalse($body['enabled']);
        self::assertSame(10, $body['position']);
    }

    public function testBuildPutBodyCreatesTranslationEntryWhenMissing(): void
    {
        $current = ['code' => 'cats', 'enabled' => true, 'translations' => []];
        $requested = ['name' => 'Cats', 'slug' => null, 'description' => null, 'enabled' => null, 'position' => null];

        $body = $this->builder->buildPutBody($current, self::LOCALE, $requested);

        self::assertSame('Cats', $body['translations'][self::LOCALE]['name']);
    }

    private function clientException(int $status, array $violations = []): ClientException
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
