<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\AdminApi;

use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClient;
use Emagma\McpSyliusBundle\AdminApi\JwtTokenProviderInterface;
use Emagma\McpSyliusBundle\Tests\Unit\AdminApi\Adapter\RootCouponsAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(AdminApiClient::class)]
final class AdminApiClientTest extends TestCase
{
    #[DataProvider('presenceHeaders')]
    public function testTheShopIsRecognisedByThePluginsHeaderOldNameIncluded(array $headers, bool $warns): void
    {
        $http = new MockHttpClient(new MockResponse('{}', ['response_headers' => $headers]));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($warns ? self::once() : self::never())->method('warning');

        (new AdminApiClient($http, $this->tokens('jwt-token'), $logger))->getDecoded('channels/WEB');
    }

    /**
     * @return iterable<string, array{array<string, string>, bool}>
     */
    public static function presenceHeaders(): iterable
    {
        yield 'the plugin' => [['Sylius-Mcp-Plugin' => '1'], false];
        yield 'the plugin before its renaming' => [['Sylius-Mcp-Companion' => '1'], false];
        yield 'no plugin' => [[], true];
    }

    public function testPatchSendsMergePatchContentType(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return new MockResponse('{"code":"UPS","archivedAt":"2026-09-07T10:00:00+00:00"}');
        });

        $client = new AdminApiClient($http, $this->tokens('jwt-token'));

        $result = $client->patch('shipping-methods/UPS/archive');

        self::assertSame('UPS', $result['code']);
        self::assertCount(1, $requests);
        self::assertSame('PATCH', $requests[0][0]);
        self::assertContains('Content-Type: application/merge-patch+json', $requests[0][2]['headers']);
    }

    public function testARouteGoesWhereTheAdapterOfTheShopsLineSaysForItsVerb(): void
    {
        $requests = [];
        $bodies = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests, &$bodies): MockResponse {
            $requests[] = $method . ' ' . $url;
            if ('POST' === $method) {
                $bodies[] = $options['body'];
            }

            return new MockResponse('{}');
        }, 'http://shop/api/v2/admin/');
        $client = new AdminApiClient($http, $this->tokens('jwt-token'), null, new RootCouponsAdapter('http://shop/api/v2/admin/'));

        $client->getDecoded(Route::collection(Resource::PromotionCoupon, ['promotion' => 'BF']));
        $client->post(Route::collection(Resource::PromotionCoupon, ['promotion' => 'BF']), ['code' => 'BF25']);
        $client->postMany(['BF26' => ['path' => Route::collection(Resource::PromotionCoupon, ['promotion' => 'BF']), 'body' => ['code' => 'BF26']]]);
        $client->getDecodedMany(['BF25' => Route::item(Resource::PromotionCoupon, 'BF25', ['promotion' => 'BF']), 'raw' => 'channels/WEB']);
        $client->deleteMany([Route::item(Resource::Province, 'FR-75', ['country' => 'FR'])]);

        self::assertSame([
            'GET http://shop/api/v2/admin/promotions/BF/coupons',
            'POST http://shop/api/v2/admin/promotion-coupons',
            'POST http://shop/api/v2/admin/promotion-coupons',
            'GET http://shop/api/v2/admin/promotion-coupons/BF25',
            'GET http://shop/api/v2/admin/channels/WEB',
            'DELETE http://shop/api/v2/admin/provinces/FR-75',
        ], $requests);
        self::assertSame([
            '{"code":"BF25","promotion":"BF"}',
            '{"code":"BF26","promotion":"BF"}',
        ], $bodies, 'the adapter of the line puts the promotion of a created coupon in its body');
    }

    public function testPatchSendsAnEmptyJsonObjectWhenNoBodyIsGiven(): void
    {
        $bodies = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = $options['body'];

            return new MockResponse('{}');
        });

        (new AdminApiClient($http, $this->tokens('jwt-token')))->patch('shipping-methods/UPS/restore');

        // `[]` would encode to a JSON list, which the admin action operations reject.
        self::assertSame('{}', $bodies[0]);
    }

    public function testPatchForwardsAnExplicitBody(): void
    {
        $bodies = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = $options['body'];

            return new MockResponse('{}');
        });

        (new AdminApiClient($http, $this->tokens('jwt-token')))->patch('shipping-methods/UPS', ['enabled' => false]);

        self::assertSame('{"enabled":false}', $bodies[0]);
    }

    public function testPatchReauthenticatesOnceOn401(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return 1 === $calls
                ? new MockResponse('{}', ['http_code' => 401])
                : new MockResponse('{"code":"UPS"}');
        });

        $tokens = $this->createMock(JwtTokenProviderInterface::class);
        $tokens->method('getToken')->willReturn('jwt-token');
        $tokens->expects(self::once())->method('invalidate');

        $result = (new AdminApiClient($http, $tokens))->patch('shipping-methods/UPS/archive');

        self::assertSame('UPS', $result['code']);
        self::assertSame(2, $calls);
    }

    public function testPatchPropagatesOtherClientErrors(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{}', ['http_code' => 404]));

        $tokens = $this->createMock(JwtTokenProviderInterface::class);
        $tokens->method('getToken')->willReturn('jwt-token');
        $tokens->expects(self::never())->method('invalidate');

        $this->expectException(\Symfony\Component\HttpClient\Exception\ClientException::class);

        (new AdminApiClient($http, $tokens))->patch('shipping-methods/NOPE/archive');
    }

    // stream() checks the status of a response outside the caller's try.
    public function testGetDecodedManyDegradesAnErrorStatusToItsOwnKey(): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/NOPE')
            ? new MockResponse('{"detail":"Not Found"}', ['http_code' => 404])
            : new MockResponse('{"code":"WEB"}'));

        $results = (new AdminApiClient($http, $this->tokens('jwt-token')))->getDecodedMany(['a' => 'channels/NOPE', 'b' => 'channels/WEB']);

        self::assertFalse($results['a']['ok']);
        self::assertSame(404, $results['a']['status'] ?? null);
        self::assertSame(['ok' => true, 'body' => ['code' => 'WEB']], $results['b']);
    }

    public function testPutManyDegradesAValidationErrorToItsOwnKey(): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/BAD')
            ? new MockResponse('{"violations":[{"propertyPath":"code"}]}', ['http_code' => 422])
            : new MockResponse('{"code":"OK"}'));

        $results = (new AdminApiClient($http, $this->tokens('jwt-token')))->putMany([
            'a' => ['path' => 'channels/BAD', 'body' => []],
            'b' => ['path' => 'channels/OK', 'body' => []],
        ]);

        self::assertSame(['ok' => false, 'status' => 422, 'error' => 'HTTP 422', 'body' => ['violations' => [['propertyPath' => 'code']]]], $results['a']);
        self::assertSame(['ok' => true, 'body' => ['code' => 'OK']], $results['b']);
    }

    public function testDeleteManyDegradesAMissingResourceToItsOwnKey(): void
    {
        $http = new MockHttpClient(static fn (string $method, string $url): MockResponse => str_ends_with($url, '/NOPE')
            ? new MockResponse('{"detail":"Not Found"}', ['http_code' => 404])
            : new MockResponse('', ['http_code' => 204]));

        $results = (new AdminApiClient($http, $this->tokens('jwt-token')))->deleteMany(['a' => 'channels/NOPE', 'b' => 'channels/WEB']);

        self::assertSame(['ok' => false, 'status' => 404, 'error' => 'HTTP 404', 'body' => ['detail' => 'Not Found']], $results['a']);
        self::assertSame(['ok' => true], $results['b']);
    }

    private function tokens(string $token): JwtTokenProviderInterface
    {
        $tokens = $this->createMock(JwtTokenProviderInterface::class);
        $tokens->method('getToken')->willReturn($token);

        return $tokens;
    }
}
