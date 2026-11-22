<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\AdminApi;

use Emagma\McpSyliusBundle\AdminApi\ViolationExtractor;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ViolationExtractor::class)]
final class ViolationExtractorTest extends McpToolTestCase
{
    public function testItReadsAConstraintViolationList(): void
    {
        $exception = $this->clientException(422, [
            ['propertyPath' => 'code', 'message' => 'This value is already used.'],
        ]);

        self::assertSame(
            [['property' => 'code', 'message' => 'This value is already used.']],
            ViolationExtractor::fromException($exception),
        );
    }

    public function testItReadsBothEnvelopeKeys(): void
    {
        self::assertSame(
            [['property' => 'name', 'message' => 'Required.']],
            ViolationExtractor::fromPayload(['hydra:violations' => [['propertyPath' => 'name', 'message' => 'Required.']]]),
        );
        self::assertSame([], ViolationExtractor::fromPayload(null));
    }

    /** Not every 422 is a violation list: a domain rule failing in a service answers with a plain message. */
    public function testItFallsBackToAPlainMessage(): void
    {
        self::assertSame(
            [['property' => '', 'message' => 'Cannot delete a promotion being processed.']],
            ViolationExtractor::fromPayload(['detail' => 'Cannot delete a promotion being processed.']),
        );
    }
}
