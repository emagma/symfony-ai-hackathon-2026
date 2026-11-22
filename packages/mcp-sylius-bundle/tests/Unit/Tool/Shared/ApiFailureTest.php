<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Shared;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(ApiFailure::class)]
final class ApiFailureTest extends McpToolTestCase
{
    public function testA404IsNotFoundWithTheToolsOwnKeysFirstAfterOk(): void
    {
        $failure = ApiFailure::describe($this->clientException(404), ['deleted' => false, 'code' => 'mug']);

        self::assertSame(['ok' => false, 'deleted' => false, 'code' => 'mug', 'reason' => 'not_found'], $failure);
    }

    public function testA422CarriesTheShopsViolations(): void
    {
        $exception = $this->clientException(422, [['propertyPath' => 'code', 'message' => 'Already used.']]);

        self::assertSame(
            ['ok' => false, 'reason' => 'validation_error', 'errors' => [['property' => 'code', 'message' => 'Already used.']]],
            ApiFailure::describe($exception),
        );
    }

    public function testA409IsAConflictWithTheShopsExplanation(): void
    {
        $exception = $this->clientException(409, [['propertyPath' => '', 'message' => 'In use by an order.']]);

        self::assertSame(
            ['ok' => false, 'reason' => 'conflict', 'errors' => [['property' => '', 'message' => 'In use by an order.']]],
            ApiFailure::describe($exception),
        );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function unmappedStatuses(): iterable
    {
        yield 'bad request' => [400];
        yield 'unauthorized after the reauth' => [401];
        yield 'forbidden' => [403];
        yield 'server error' => [500];
        yield 'gateway timeout' => [504];
    }

    #[DataProvider('unmappedStatuses')]
    public function testAnyOtherStatusIsAnUpstreamErrorWithTheStatusAndNothingOfTheBody(int $status): void
    {
        $failure = ApiFailure::describe($this->clientException($status), ['updated' => false]);

        self::assertSame(['ok' => false, 'updated' => false, 'reason' => 'upstream_error', 'status' => $status], $failure);
        self::assertStringNotContainsString('https://test/', json_encode($failure, \JSON_THROW_ON_ERROR));
    }

    public function testTheBaseCannotOverrideOk(): void
    {
        $failure = ApiFailure::describe($this->clientException(404), ['ok' => true, 'code' => 'mug']);

        self::assertFalse($failure['ok']);
    }
}
