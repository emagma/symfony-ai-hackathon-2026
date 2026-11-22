<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Product;

use Emagma\McpSyliusBundle\AdminApi\Adapter\DefaultLayoutAdapter;
use Emagma\McpSyliusBundle\Tool\Product\ProductUpdatePayloadBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(ProductUpdatePayloadBuilder::class)]
final class ProductUpdatePayloadBuilderTest extends TestCase
{
    private const string LOCALE = 'en_US';

    private ProductUpdatePayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ProductUpdatePayloadBuilder(new DefaultLayoutAdapter('http://shop.test/api/v2/admin/'));
    }

    public function testComputeDiffDetectsNameChange(): void
    {
        $current = $this->minimalProduct('p1', 'Old Name');
        $requested = $this->requestedWith(['name' => 'New Name']);

        $diff = $this->builder->computeDiff($current, self::LOCALE, $requested);

        self::assertSame(
            [self::LOCALE . '.name' => ['before' => 'Old Name', 'after' => 'New Name']],
            $diff,
        );
    }

    public function testComputeDiffReturnsEmptyWhenNothingChanged(): void
    {
        $current = $this->minimalProduct('p1', 'Same Name');
        $requested = $this->requestedWith(['name' => 'Same Name']);

        $diff = $this->builder->computeDiff($current, self::LOCALE, $requested);

        self::assertSame([], $diff);
    }

    public function testComputeDiffDetectsEnabledChange(): void
    {
        $current = $this->minimalProduct('p1', 'Name', enabled: true);
        $requested = $this->requestedWith(['enabled' => false]);

        $diff = $this->builder->computeDiff($current, self::LOCALE, $requested);

        self::assertSame(['enabled' => ['before' => true, 'after' => false]], $diff);
    }

    public function testComputeDiffSkipsEnabledWhenUnchanged(): void
    {
        $current = $this->minimalProduct('p1', 'Name', enabled: true);
        $requested = $this->requestedWith(['enabled' => true]);

        $diff = $this->builder->computeDiff($current, self::LOCALE, $requested);

        self::assertSame([], $diff);
    }

    public function testComputeDiffDetectsChannelCodesChange(): void
    {
        $current = $this->minimalProduct('p1', 'Name');
        $current['channels'] = ['/api/v2/admin/channels/WEB'];

        $requested = $this->requestedWith(['channelCodes' => ['MOBILE']]);

        $diff = $this->builder->computeDiff($current, self::LOCALE, $requested);

        self::assertArrayHasKey('channelCodes', $diff);
        self::assertSame(['WEB'], $diff['channelCodes']['before']);
        self::assertSame(['MOBILE'], $diff['channelCodes']['after']);
    }

    public function testComputeDiffDetectsMainTaxonCodeChange(): void
    {
        $current = $this->minimalProduct('p1', 'Name');
        $current['mainTaxon'] = '/api/v2/admin/taxons/old_taxon';

        $requested = $this->requestedWith(['mainTaxonCode' => 'new_taxon']);

        $diff = $this->builder->computeDiff($current, self::LOCALE, $requested);

        self::assertArrayHasKey('mainTaxonCode', $diff);
        self::assertSame('old_taxon', $diff['mainTaxonCode']['before']);
        self::assertSame('new_taxon', $diff['mainTaxonCode']['after']);
    }

    public function testComputeDiffDetachesMainTaxonWithEmptyString(): void
    {
        $current = $this->minimalProduct('p1', 'Name');
        $current['mainTaxon'] = '/api/v2/admin/taxons/some_taxon';

        $requested = $this->requestedWith(['mainTaxonCode' => '']); // empty string = detach

        $diff = $this->builder->computeDiff($current, self::LOCALE, $requested);

        self::assertArrayHasKey('mainTaxonCode', $diff);
        self::assertSame('some_taxon', $diff['mainTaxonCode']['before']);
        self::assertNull($diff['mainTaxonCode']['after']);
    }

    public function testBuildPutBodyAppliesNameChange(): void
    {
        $current = $this->minimalProduct('p1', 'Old Name');
        $requested = $this->requestedWith(['name' => 'New Name']);

        $body = $this->builder->buildPutBody($current, self::LOCALE, $requested, []);

        self::assertSame('New Name', $body['translations'][self::LOCALE]['name']);
        self::assertSame('old-name', $body['translations'][self::LOCALE]['slug']);
    }

    public function testBuildPutBodyAppliesEnabledChange(): void
    {
        $current = $this->minimalProduct('p1', 'Name', enabled: true);
        $requested = $this->requestedWith(['enabled' => false]);

        $body = $this->builder->buildPutBody($current, self::LOCALE, $requested, []);

        self::assertFalse($body['enabled']);
    }

    public function testBuildPutBodySetsMainTaxonIriFromReferences(): void
    {
        $current = $this->minimalProduct('p1', 'Name');
        $requested = $this->requestedWith(['mainTaxonCode' => 'new_taxon']);
        $references = ['mainTaxonIri' => '/api/v2/admin/taxons/new_taxon'];

        $body = $this->builder->buildPutBody($current, self::LOCALE, $requested, $references);

        self::assertSame('/api/v2/admin/taxons/new_taxon', $body['mainTaxon']);
    }

    public function testBuildPutBodyDetachesMainTaxonWithEmptyString(): void
    {
        $current = $this->minimalProduct('p1', 'Name');
        $current['mainTaxon'] = '/api/v2/admin/taxons/old';
        $requested = $this->requestedWith(['mainTaxonCode' => '']); // detach

        $body = $this->builder->buildPutBody($current, self::LOCALE, $requested, []);

        self::assertNull($body['mainTaxon']);
    }

    public function testBuildPutBodyAppliesChannelIrisFromReferences(): void
    {
        $current = $this->minimalProduct('p1', 'Name');
        $requested = $this->requestedWith(['channelCodes' => ['WEB', 'MOBILE']]);
        $references = ['channelIris' => ['/api/v2/admin/channels/WEB', '/api/v2/admin/channels/MOBILE']];

        $body = $this->builder->buildPutBody($current, self::LOCALE, $requested, $references);

        self::assertSame(
            ['/api/v2/admin/channels/WEB', '/api/v2/admin/channels/MOBILE'],
            $body['channels'],
        );
    }

    public function testBuildPutBodyBuildsAttributeListFromReferences(): void
    {
        $current = $this->minimalProduct('p1', 'Name');
        $requested = $this->requestedWith([
            'attributes' => [['code' => 'color', 'locale' => self::LOCALE, 'value' => 'red']],
        ]);
        $references = ['attributeIris' => ['color' => '/api/v2/admin/product_attributes/color']];

        $body = $this->builder->buildPutBody($current, self::LOCALE, $requested, $references);

        self::assertCount(1, $body['attributes']);
        self::assertSame('/api/v2/admin/product_attributes/color', $body['attributes'][0]['attribute']);
        self::assertSame(self::LOCALE, $body['attributes'][0]['localeCode']);
        self::assertSame('red', $body['attributes'][0]['value']);
    }

    /**
     * @param array{propertyPath?: string, message?: string}[] $violations
     */
    private function clientException(int $status, array $violations = []): ClientException
    {
        $payload = [] === $violations ? [] : ['violations' => $violations];
        $body = [] === $payload ? '' : (string) json_encode($payload);

        /** @var ResponseInterface&MockObject $response */
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

    /** @return array<string, mixed> */
    private function minimalProduct(string $code, string $name, bool $enabled = true): array
    {
        return [
            'code' => $code,
            'enabled' => $enabled,
            'translations' => [
                self::LOCALE => ['name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name))],
            ],
            'channels' => [],
            'options' => [],
            'attributes' => [],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{
     *     name: ?string, slug: ?string, description: ?string, shortDescription: ?string,
     *     metaKeywords: ?string, metaDescription: ?string,
     *     enabled: ?bool, mainTaxonCode: ?string,
     *     channelCodes: list<string>|null, optionCodes: list<string>|null,
     *     attributes: list<array{code?: mixed, locale?: mixed, value?: mixed}>|null
     * }
     */
    private function requestedWith(array $overrides): array
    {
        return array_merge([
            'name' => null,
            'slug' => null,
            'description' => null,
            'shortDescription' => null,
            'metaKeywords' => null,
            'metaDescription' => null,
            'enabled' => null,
            'mainTaxonCode' => null,
            'channelCodes' => null,
            'optionCodes' => null,
            'attributes' => null,
        ], $overrides);
    }
}
