<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductOption;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductOption\UpsertProductOptions;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertProductOptions::class)]
final class UpsertProductOptionsTest extends McpToolTestCase
{
    public function testACreationFillsEveryLocaleForTheOptionAndItsValues(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())->method('getDecoded')->with('locales', ['itemsPerPage' => 100])
            ->willReturn($this->collection([['code' => 'en_US'], ['code' => 'fr_FR']]));
        $client->expects(self::once())->method('getDecodedMany')
            ->willReturn(['size' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404']]);
        $client->expects(self::once())->method('postMany')
            ->with(['size' => ['path' => 'product-options', 'body' => [
                'code' => 'size',
                'translations' => ['en_US' => ['name' => 'Size', 'locale' => 'en_US'], 'fr_FR' => ['name' => 'Size', 'locale' => 'fr_FR']],
                'position' => 1,
                'values' => [
                    ['code' => 's', 'translations' => ['en_US' => ['value' => 'Small', 'locale' => 'en_US'], 'fr_FR' => ['value' => 'Small', 'locale' => 'fr_FR']]],
                    ['code' => 'm', 'translations' => ['en_US' => ['value' => 'Medium', 'locale' => 'en_US'], 'fr_FR' => ['value' => 'Medium', 'locale' => 'fr_FR']]],
                ],
            ]]])
            ->willReturn(['size' => ['ok' => true, 'body' => ['code' => 'size']]]);

        $result = $this->call(new UpsertProductOptions($client, $this->audit(), 'en_US'), items: [
            ['code' => 'size', 'name' => 'Size', 'position' => 1, 'values' => ['s' => 'Small', 'm' => 'Medium']],
        ], confirm: true);

        self::assertSame('created', $result['results']['size']['action']);
        self::assertSame(['before' => null, 'after' => ['s', 'm']], $result['results']['size']['diff']['values']);
    }

    public function testACreationWithoutANameFailsItsItemBeforeReachingTheShop(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn(['color' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404']]);
        $client->expects(self::never())->method('postMany');

        $result = $this->call(new UpsertProductOptions($client, $this->audit(), 'en_US'), items: [
            ['code' => 'color', 'values' => ['color_red' => 'Red']],
        ], confirm: true);

        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'fields_required', 'fields' => ['name']], $result['results']['color']);
    }

    public function testTwoOptionsRewritingTheirValuesFanOutTheValueIrisOnce(): void
    {
        $size = ['@id' => '/api/v2/admin/product-options/size', 'code' => 'size', 'translations' => ['en_US' => ['name' => 'Size']],
            'values' => ['/api/v2/admin/product-options/size/values/s', '/api/v2/admin/product-options/size/values/m']];
        $color = ['@id' => '/api/v2/admin/product-options/color', 'code' => 'color', 'translations' => ['en_US' => ['name' => 'Color']],
            'values' => ['/api/v2/admin/product-options/color/values/red', '/api/v2/admin/product-options/color/values/42']];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $fanOuts = [];
        $client->method('getDecodedMany')->willReturnCallback(static function (array $paths) use ($size, $color, &$fanOuts): array {
            $fanOuts[] = array_keys($paths);
            $out = [];
            foreach ($paths as $key => $path) {
                $path = (string) $path;
                $out[$key] = match ($key) {
                    'size' => ['ok' => true, 'body' => $size],
                    'color' => ['ok' => true, 'body' => $color],
                    '/api/v2/admin/product-options/size/values/s' => ['ok' => true, 'body' => ['code' => 's', 'translations' => ['en_US' => ['@id' => '/t/1', 'value' => 'Small', 'locale' => 'en_US']]]],
                    '/api/v2/admin/product-options/size/values/m' => ['ok' => true, 'body' => ['code' => 'm', 'translations' => ['en_US' => ['@id' => '/t/2', 'value' => 'Medium', 'locale' => 'en_US']]]],
                    '/api/v2/admin/product-options/color/values/red' => ['ok' => true, 'body' => ['code' => 'red', 'translations' => ['en_US' => ['@id' => '/t/3', 'value' => 'Red', 'locale' => 'en_US']]]],
                    '/api/v2/admin/product-options/color/values/42' => ['ok' => true, 'body' => ['code' => '42', 'translations' => ['en_US' => ['@id' => '/t/4', 'value' => 'Forty-two', 'locale' => 'en_US']]]],
                    default => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
                };
            }

            return $out;
        });
        $client->expects(self::once())->method('putMany')
            ->with([
                'size' => ['path' => 'product-options/size', 'body' => array_replace($size, ['values' => [
                    '/api/v2/admin/product-options/size/values/s',
                    ['@id' => '/api/v2/admin/product-options/size/values/m', 'code' => 'm', 'translations' => ['en_US' => ['@id' => '/t/2', 'value' => 'Medium (M)', 'locale' => 'en_US']]],
                    ['code' => 'l', 'translations' => ['en_US' => ['value' => 'Large', 'locale' => 'en_US']]],
                ]])],
                'color' => ['path' => 'product-options/color', 'body' => array_replace($color, ['values' => ['/api/v2/admin/product-options/color/values/42']])],
            ])
            ->willReturn(['size' => ['ok' => true, 'body' => $size], 'color' => ['ok' => true, 'body' => $color]]);

        $result = $this->call(new UpsertProductOptions($client, $this->audit(), 'en_US'), items: [
            ['code' => 'size', 'values' => ['s' => 'Small', 'm' => 'Medium (M)', 'l' => 'Large']],
            ['code' => 'color', 'values' => [42 => 'Forty-two']],
        ], confirm: true);

        self::assertCount(2, $fanOuts, 'one probe, one value fan-out for both options');
        self::assertSame(['size', 'color'], $fanOuts[0]);
        self::assertCount(4, $fanOuts[1]);
        self::assertSame(
            ['value.m' => ['before' => 'Medium', 'after' => 'Medium (M)'], 'value.l' => ['before' => null, 'after' => 'Large']],
            $result['results']['size']['diff'],
        );
        self::assertSame(['value.red' => ['before' => 'Red', 'after' => null]], $result['results']['color']['diff'], 'a numeric value code is a code; red is deleted');
    }

    public function testAnOptionWhoseValuesCannotAllBeReadFailsInsteadOfLosingThem(): void
    {
        $size = ['code' => 'size', 'translations' => [], 'values' => ['/api/v2/admin/product-options/size/values/s', '/api/v2/admin/product-options/size/values/m']];
        $color = ['code' => 'color', 'translations' => [], 'values' => ['/api/v2/admin/product-options/color/values/red']];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturnCallback(static function (array $paths) use ($size, $color): array {
            $out = [];
            foreach ($paths as $key => $path) {
                $path = (string) $path;
                $out[$key] = match ($key) {
                    'size' => ['ok' => true, 'body' => $size],
                    'color' => ['ok' => true, 'body' => $color],
                    '/api/v2/admin/product-options/size/values/s' => ['ok' => true, 'body' => ['code' => 's', 'translations' => ['en_US' => ['value' => 'Small']]]],
                    '/api/v2/admin/product-options/size/values/m' => ['ok' => false, 'status' => 503, 'error' => 'HTTP 503'],
                    '/api/v2/admin/product-options/color/values/red' => ['ok' => true, 'body' => ['code' => 'red', 'translations' => ['en_US' => ['value' => 'Red']]]],
                    default => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
                };
            }

            return $out;
        });
        $client->expects(self::once())->method('putMany')
            ->with(self::callback(static fn (array $requests): bool => ['color'] === array_keys($requests)))
            ->willReturn(['color' => ['ok' => true, 'body' => $color]]);

        $result = $this->call(new UpsertProductOptions($client, $this->audit(), 'en_US'), items: [
            ['code' => 'size', 'values' => ['s' => 'Small']],
            ['code' => 'color', 'values' => ['red' => 'Rouge']],
        ], confirm: true);

        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'upstream_error', 'status' => 503], $result['results']['size']);
        self::assertSame('updated', $result['results']['color']['action']);
        self::assertSame(1, $result['failed']);
    }

    public function testTheSameValuesAreUnchanged(): void
    {
        $size = ['code' => 'size', 'translations' => ['en_US' => ['name' => 'Size']], 'values' => ['/api/v2/admin/product-options/size/values/s']];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturnCallback(static fn (array $paths): array => array_map(
            static fn (\Stringable|string $path): array => str_contains((string) $path, '/values/')
                ? ['ok' => true, 'body' => ['code' => 's', 'translations' => ['en_US' => ['value' => 'Small']]]]
                : ['ok' => true, 'body' => $size],
            $paths,
        ));
        $client->expects(self::never())->method('putMany');

        $result = $this->call(new UpsertProductOptions($client, $this->audit(), 'en_US'), items: [['code' => 'size', 'name' => 'Size', 'values' => ['s' => 'Small']]]);

        self::assertSame('unchanged', $result['results']['size']['action']);
    }
}
