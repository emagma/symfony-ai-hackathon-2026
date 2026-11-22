<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductImage;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\ProductImage\ProductImageOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Sylius removes the file on disk too: irreversible, hence not covered by revert_mcp_edit.
 *
 * @internal
 */
#[McpTool(
    name: 'delete_product_image',
    description: 'Delete a single Sylius admin product image. Requires the parent product `code` and the numeric'
        . ' image `id`. DESTRUCTIVE - Sylius removes both the DB row and the file on disk. Returns'
        . ' {deleted: true, code, id, before} on success. Not revertable: restoring an image would also'
        . ' need the file re-uploaded.',
)]
#[Permission(resource: 'product_image', action: Action::Delete)]
final readonly class DeleteProductImage
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'product_image';

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $code,
        int $id,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        try {
            $current = $this->client->getDecoded(
                Route::item(Resource::ProductImage, $id, ['product' => $code]),
            );
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['deleted' => false, 'code' => $code, 'id' => $id]);
        }

        $shaped = ProductImageOutput::fromPayload($current, $code, $id);

        $preview = $this->renderPreview($code, $id, $shaped);
        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm product image deletion of "' . $code . '" #' . $id,
            'Delete this product image?',
            $preview,
            'Re-invoke with confirm: true to apply this deletion.',
        );
        if (null !== $refusal) {
            $out = ['ok' => false, 'deleted' => false, 'code' => $code, 'id' => $id] + $refusal;
            if (isset($refusal['hint'])) {
                $out['preview'] = $preview;
            }

            return $out;
        }

        try {
            $this->client->delete(Route::item(Resource::ProductImage, $id, ['product' => $code]));
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['deleted' => false, 'code' => $code, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'delete_product_image',
            targetType: self::TARGET_TYPE,
            targetCode: $code . ':' . $id,
            beforeState: $current,
            afterState: [],
            diff: ['deleted' => ['before' => false, 'after' => true]],
            context: $context,
        );

        return [
            'ok' => true,
            'deleted' => true,
            'code' => $code,
            'id' => $id,
            'before' => $shaped,
        ];
    }

    private function renderPreview(string $code, int $id, ProductImageOutput $shaped): string
    {
        $path = $shaped->path;
        $type = $shaped->type;

        return implode("\n", [
            'Delete product image "' . $code . '" #' . $id,
            '',
            '- path: ' . ($path ?? '(none)'),
            '- type: ' . ($type ?? 'null'),
            '',
            'The image file on disk is removed too. Not revertable via revert_mcp_edit.',
        ]);
    }
}
