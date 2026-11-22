<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductOption;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductOption\AddProductOptionValue;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AddProductOptionValue::class)]
final class AddProductOptionValueTest extends McpToolTestCase
{
    private const string LOCALE = 'en_US';

    private const string OPTION_PATH = 'product-options/opt_size';

    private const string VALUE_IRI_S = '/api/v2/admin/product-options/opt_size/values/opt_size_s';

    public function testItDeclaresTheMcpToolAttribute(): void
    {
        $attributes = (new \ReflectionClass(AddProductOptionValue::class))->getAttributes(McpTool::class);

        self::assertCount(1, $attributes);
        self::assertSame('add_product_option_value', $attributes[0]->newInstance()->name);
    }

    public function testItReturnsNotFoundWhenOptionAbsent(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new AddProductOptionValue($client, $this->audit(), self::LOCALE),
            code: 'opt_missing',
            valueCode: 'opt_size_l',
            label: 'Large',
            confirm: true,
        );

        self::assertFalse($result['added']);
        self::assertSame('not_found', $result['reason']);
    }

    public function testItRefusesAnExistingValueCode(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match ((string) $path) {
            self::OPTION_PATH => $this->optionPayload(),
            self::OPTION_PATH . '/values/opt_size_s' => [
                'code' => 'opt_size_s',
                'translations' => [self::LOCALE => ['value' => 'Small']],
            ],
            default => throw new \LogicException('unexpected path ' . $path),
        });
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new AddProductOptionValue($client, $this->audit(), self::LOCALE),
            code: 'opt_size',
            valueCode: 'opt_size_s',
            label: 'Small',
            confirm: true,
        );

        self::assertFalse($result['added']);
        self::assertSame('value_already_exists', $result['reason']);
        self::assertSame('Small', $result['label']);
    }

    public function testItAppendsTheValueAndPreservesExistingOnesVerbatim(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match (true) {
            self::OPTION_PATH === (string) $path => $this->optionPayload(),
            str_contains((string) $path, '/values/') => throw $this->clientException(404),
            default => throw new \LogicException('unexpected path ' . $path),
        });

        $client->expects(self::once())
            ->method('put')
            ->with(
                self::OPTION_PATH,
                self::callback(static function (array $body): bool {
                    // The pre-existing value travels back as it arrived: an IRI string, never re-derived.
                    self::assertSame(self::VALUE_IRI_S, $body['values'][0]);
                    self::assertSame([
                        'code' => 'opt_size_l',
                        'translations' => [self::LOCALE => ['value' => 'Large', 'locale' => self::LOCALE]],
                    ], $body['values'][1]);
                    self::assertCount(2, $body['values']);

                    return true;
                }),
                [],
            )
            ->willReturn($this->optionPayload());

        $result = $this->call(
            new AddProductOptionValue($client, $this->audit(), self::LOCALE),
            code: 'opt_size',
            valueCode: 'opt_size_l',
            label: 'Large',
            confirm: true,
        );

        self::assertTrue($result['added']);
        self::assertSame('opt_size_l', $result['valueCode']);
        self::assertSame(['value.opt_size_l' => ['before' => null, 'after' => 'Large']], $result['diff']);
    }

    public function testItRecordsAnAuditEntry(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match (true) {
            self::OPTION_PATH === (string) $path => $this->optionPayload(),
            default => throw $this->clientException(404),
        });
        $client->method('put')->willReturn($this->optionPayload());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'add_product_option_value' === $e->getTool() &&
                'product_option' === $e->getTargetType() &&
                'opt_size' === $e->getTargetCode(),
        ));

        $this->call(
            new AddProductOptionValue($client, $audit, self::LOCALE),
            code: 'opt_size',
            valueCode: 'opt_size_l',
            label: 'Large',
            confirm: true,
        );
    }

    public function testItMapsValidationErrors(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match (true) {
            self::OPTION_PATH === (string) $path => $this->optionPayload(),
            default => throw $this->clientException(404),
        });
        $client->method('put')->willThrowException($this->clientException(422, [
            ['propertyPath' => 'values[1].code', 'message' => 'This value is already used.'],
        ]));

        $result = $this->call(
            new AddProductOptionValue($client, $this->audit(), self::LOCALE),
            code: 'opt_size',
            valueCode: 'opt_size_l',
            label: 'Large',
            confirm: true,
        );

        self::assertFalse($result['added']);
        self::assertSame('validation_error', $result['reason']);
        self::assertSame([['property' => 'values[1].code', 'message' => 'This value is already used.']], $result['errors']);
    }

    public function testItReturnsPreviewOnlyWithoutElicitation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match (true) {
            self::OPTION_PATH === (string) $path => $this->optionPayload(),
            default => throw $this->clientException(404),
        });
        $client->expects(self::never())->method('put');

        $result = $this->call(
            new AddProductOptionValue($client, $this->audit(), self::LOCALE),
            code: 'opt_size',
            valueCode: 'opt_size_l',
            label: 'Large',
            context: $this->noElicitContext(),
        );

        self::assertFalse($result['added']);
        self::assertSame('human_confirmation_required', $result['reason']);
    }

    public function testElicitationAcceptedProceedsToWrite(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match (true) {
            self::OPTION_PATH === (string) $path => $this->optionPayload(),
            default => throw $this->clientException(404),
        });
        $client->expects(self::once())->method('put')->willReturn($this->optionPayload());

        $tool = new AddProductOptionValue($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: 'opt_size',
                valueCode: 'opt_size_l',
                label: 'Large',
                context: $context,
            ),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['added']);
    }

    public function testElicitationDeclinedDoesNotWrite(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match (true) {
            self::OPTION_PATH === (string) $path => $this->optionPayload(),
            default => throw $this->clientException(404),
        });
        $client->expects(self::never())->method('put');

        $tool = new AddProductOptionValue($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: 'opt_size',
                valueCode: 'opt_size_l',
                label: 'Large',
                context: $context,
            ),
            ElicitAction::Decline,
        );

        self::assertFalse($result['added']);
        self::assertSame('declined', $result['reason']);
    }

    public function testElicitationOverridesModelSuppliedConfirm(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturnCallback(fn (\Stringable|string $path): array => match (true) {
            self::OPTION_PATH === (string) $path => $this->optionPayload(),
            default => throw $this->clientException(404),
        });
        $client->expects(self::never())->method('put');

        $tool = new AddProductOptionValue($client, $this->audit(), self::LOCALE);
        $context = $this->elicitingContext();

        // confirm: true is model-supplied and must not survive a human decline.
        $result = $this->runWithElicitation(
            static fn (): array => $tool(
                code: 'opt_size',
                valueCode: 'opt_size_l',
                label: 'Large',
                confirm: true,
                context: $context,
            ),
            ElicitAction::Decline,
        );

        self::assertFalse($result['added']);
    }

    /**
     * @return array<string, mixed>
     */
    private function optionPayload(): array
    {
        return [
            '@id' => '/api/v2/admin/product-options/opt_size',
            'code' => 'opt_size',
            'translations' => [self::LOCALE => ['name' => 'Size']],
            'values' => [self::VALUE_IRI_S],
        ];
    }
}
