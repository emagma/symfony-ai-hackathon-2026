<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductImage;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\ProductImage\ProductImageOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Emagma\McpSyliusBundle\Tool\Shared\SortablePositionNotice;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * The Sylius admin PUT requires the full current payload, whatever the fields being changed.
 *
 * @internal
 */
#[McpTool(
    name: 'update_product_image',
    description: 'Update a Sylius admin product image. Requires the parent product `code` and the numeric image'
        . ' `id`. Editable: type (free-form discriminator - pass an empty string to clear it), position'
        . ' (gallery order), variantCodes (list of product variant codes the image applies to - FULL'
        . ' REPLACEMENT; an empty list makes the image apply to the whole product). The image FILE itself'
        . ' cannot be changed: delete and re-upload with create_product_image. Returns {updated: true,'
        . ' code, id, diff, before, after} on success. Tool-specific reasons: variants_not_found.'
        . SortablePositionNotice::POSITION,
)]
#[Permission(resource: 'product_image', action: Action::Write)]
final readonly class UpdateProductImage
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'product_image';

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @param list<string>|null $variantCodes
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $code,
        int $id,
        ?string $type = null,
        ?int $position = null,
        ?array $variantCodes = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        if (null === $type && null === $position && null === $variantCodes) {
            return ['ok' => false, 'updated' => false, 'reason' => 'no_change_requested', 'code' => $code, 'id' => $id];
        }

        try {
            $current = $this->client->getDecoded(
                Route::item(Resource::ProductImage, $id, ['product' => $code]),
            );
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'code' => $code, 'id' => $id]);
        }

        $diff = [];

        $typeAfter = null;
        if (null !== $type) {
            $typeBefore = \is_string($current['type'] ?? null) ? $current['type'] : null;
            $typeAfter = '' === $type ? null : $type;
            if ($typeBefore !== $typeAfter) {
                $diff['type'] = ['before' => $typeBefore, 'after' => $typeAfter];
            }
        }

        if (null !== $position) {
            $positionBefore = \is_int($current['position'] ?? null) ? $current['position'] : null;
            if ($positionBefore !== $position) {
                $diff['position'] = ['before' => $positionBefore, 'after' => $position];
            }
        }

        $variantIris = null;
        if (null !== $variantCodes) {
            $requested = self::stringList($variantCodes);
            $beforeCodes = ProductImageOutput::fromPayload($current, $code, $id)->variantCodes;

            $sortedBefore = $beforeCodes;
            $sortedAfter = $requested;
            sort($sortedBefore);
            sort($sortedAfter);
            if ($sortedBefore !== $sortedAfter) {
                // Variants travel as IRIs: a typo must come back as a named code, not a 422 property path.
                $missing = [];
                $variantIris = [];
                if ([] !== $requested) {
                    $paths = [];
                    foreach ($requested as $variantCode) {
                        $paths[$variantCode] = Route::item(Resource::ProductVariant, $variantCode);
                    }
                    $results = $this->client->getDecodedMany($paths);
                    foreach ($requested as $variantCode) {
                        $result = $results[$variantCode] ?? null;
                        $iri = (null !== $result && true === $result['ok']) ? ($result['body']['@id'] ?? null) : null;
                        if (\is_string($iri) && '' !== $iri) {
                            $variantIris[] = $iri;
                        } else {
                            $missing[] = $variantCode;
                        }
                    }
                }

                if ([] !== $missing) {
                    return [
                        'ok' => false,
                        'updated' => false,
                        'reason' => 'variants_not_found',
                        'code' => $code,
                        'id' => $id,
                        'missing' => $missing,
                    ];
                }

                $diff['variantCodes'] = ['before' => $beforeCodes, 'after' => $requested];
            }
        }

        if ([] === $diff) {
            return ['ok' => false, 'updated' => false, 'reason' => 'no_change', 'code' => $code, 'id' => $id];
        }

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm product image update on "' . $code . '" #' . $id,
            'Apply this product image update?',
            $this->renderPreview($code, $id, $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'updated' => false, 'code' => $code, 'id' => $id, 'diff' => $diff] + $refusal;
        }

        $body = $current;
        if (\array_key_exists('type', $diff)) {
            $body['type'] = $typeAfter;
        }
        if (\array_key_exists('position', $diff)) {
            $body['position'] = $position;
        }
        if (null !== $variantIris) {
            $body['productVariants'] = $variantIris;
        }

        try {
            $updated = $this->client->put(
                Route::item(Resource::ProductImage, $id, ['product' => $code]),
                $body,
            );
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'code' => $code, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'update_product_image',
            targetType: self::TARGET_TYPE,
            targetCode: $code . ':' . $id,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'updated' => true,
            'code' => $code,
            'id' => $id,
            'diff' => $diff,
            'before' => ProductImageOutput::fromPayload($current, $code, $id),
            'after' => ProductImageOutput::fromPayload($updated, $code, $id),
        ];
    }

    /**
     * @param array<string, array{before: mixed, after: mixed}> $diff
     */
    private function renderPreview(string $code, int $id, array $diff): string
    {
        $lines = ['Update product image "' . $code . '" #' . $id, ''];
        foreach ($diff as $field => $change) {
            $lines[] = sprintf(
                '- %s: %s -> %s',
                $field,
                DiffPreview::format($change['before']),
                DiffPreview::format($change['after']),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $out = [];
        foreach ($values as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $out[] = trim($value);
            }
        }

        return array_values(array_unique($out));
    }
}
