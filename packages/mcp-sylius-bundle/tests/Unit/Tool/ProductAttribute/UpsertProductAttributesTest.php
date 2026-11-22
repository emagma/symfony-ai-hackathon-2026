<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\ProductAttribute;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\ProductAttribute\UpsertProductAttributes;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpsertProductAttributes::class)]
final class UpsertProductAttributesTest extends McpToolTestCase
{
    public function testACreationNeedsAType(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn([
            'material' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
            'notes' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
        ]);
        $client->expects(self::once())->method('postMany')
            ->with(['notes' => ['path' => 'product-attributes', 'body' => [
                'code' => 'notes',
                'type' => 'select',
                'translatable' => false,
                'translations' => ['en_US' => ['name' => 'Notes', 'locale' => 'en_US']],
                'configuration' => ['multiple' => true, 'min' => 1, 'max' => null, 'choices' => ['woody' => ['en_US' => 'Woody'], 42 => ['en_US' => 'Forty-two']]],
            ]]])
            ->willReturn(['notes' => ['ok' => true, 'body' => ['code' => 'notes']]]);

        $result = $this->call(new UpsertProductAttributes($client, $this->audit(), 'en_US'), items: [
            ['code' => 'material', 'name' => 'Material'],
            ['code' => 'notes', 'name' => 'Notes', 'type' => 'select', 'multiple' => true, 'min' => 1, 'choices' => ['woody' => 'Woody', 42 => 'Forty-two']],
        ], confirm: true);

        self::assertSame('type_required', $result['results']['material']['reason']);
        self::assertSame('created', $result['results']['notes']['action']);
        self::assertSame(['before' => null, 'after' => ['woody', '42']], $result['results']['notes']['diff']['choices']);
    }

    public function testTheTypeCannotChangeAndChoiceLabelsInOtherLocalesSurviveAReplace(): void
    {
        $notes = ['code' => 'notes', 'type' => 'select', 'translatable' => false, 'translations' => ['en_US' => ['name' => 'Notes']],
            'configuration' => ['multiple' => true, 'min' => null, 'max' => null, 'choices' => ['woody' => ['en_US' => 'Woody', 'fr_FR' => 'Boisé'], 'fresh' => ['en_US' => 'Fresh']]]];
        $material = ['code' => 'material', 'type' => 'text', 'translatable' => true, 'translations' => []];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn([
            'notes' => ['ok' => true, 'body' => $notes],
            'material' => ['ok' => true, 'body' => $material],
        ]);
        $client->expects(self::once())->method('putMany')
            ->with(['notes' => ['path' => 'product-attributes/notes', 'body' => array_replace($notes, ['configuration' => [
                'multiple' => true, 'min' => null, 'max' => 3,
                'choices' => ['woody' => ['fr_FR' => 'Boisé', 'en_US' => 'Woody notes'], 'citrus' => ['en_US' => 'Citrus']],
            ]])]])
            ->willReturn(['notes' => ['ok' => true, 'body' => $notes]]);

        $result = $this->call(new UpsertProductAttributes($client, $this->audit(), 'en_US'), items: [
            ['code' => 'notes', 'max' => 3, 'choices' => ['woody' => 'Woody notes', 'citrus' => 'Citrus']],
            ['code' => 'material', 'type' => 'select'],
        ], confirm: true);

        self::assertSame(['ok' => false, 'action' => 'failed', 'reason' => 'type_immutable', 'type' => 'text'], $result['results']['material']);
        self::assertSame([
            'max' => ['before' => null, 'after' => 3],
            'choices' => ['before' => ['woody', 'fresh'], 'after' => ['woody', 'citrus']],
            'choice.woody' => ['before' => 'Woody', 'after' => 'Woody notes'],
        ], $result['results']['notes']['diff']);
    }

    public function testTheModeVerdictComesBeforeTheHookChecks(): void
    {
        $material = ['code' => 'material', 'type' => 'text', 'translatable' => true, 'translations' => []];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn([
            'material' => ['ok' => true, 'body' => $material],
            'notes' => ['ok' => false, 'status' => 404, 'error' => 'HTTP 404'],
        ]);
        $client->expects(self::never())->method('postMany');
        $client->expects(self::never())->method('putMany');

        $update = $this->call(new UpsertProductAttributes($client, $this->audit(), 'en_US'), items: [['code' => 'notes', 'name' => 'Notes']], mode: 'update');
        $create = $this->call(new UpsertProductAttributes($client, $this->audit(), 'en_US'), items: [['code' => 'material', 'type' => 'select']], mode: 'create');

        self::assertSame('not_found', $update['results']['notes']['reason'], 'not type_required: the code does not exist');
        self::assertSame('already_exists', $create['results']['material']['reason'], 'not type_immutable: the code exists');
    }

    public function testSelectConfigurationIsIgnoredOnANonSelectAttributeAndTheSameTypeIsNotAChange(): void
    {
        $material = ['code' => 'material', 'type' => 'text', 'translatable' => true, 'translations' => ['en_US' => ['name' => 'Material']]];
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([['code' => 'en_US']]));
        $client->method('getDecodedMany')->willReturn(['material' => ['ok' => true, 'body' => $material]]);
        $client->expects(self::never())->method('putMany');

        $result = $this->call(new UpsertProductAttributes($client, $this->audit(), 'en_US'), items: [
            ['code' => 'material', 'type' => 'text', 'name' => 'Material', 'choices' => ['x' => 'X'], 'multiple' => true],
        ]);

        self::assertSame('unchanged', $result['results']['material']['action']);
    }
}
