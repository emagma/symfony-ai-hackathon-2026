<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductReview;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\ProductReview\ProductReviewOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Side effect: Sylius recomputes the product's average rating.
 *
 * @internal
 */
#[McpTool(
    name: 'delete_product_review',
    description: 'Delete a Sylius admin product review permanently (id from search_product_reviews). DESTRUCTIVE'
        . ' and NOT revertable - the record is gone, and the average rating of the reviewed product is'
        . ' recomputed without it. Prefer reject_product_review, which keeps the review off the storefront'
        . ' while preserving the record. Delete only when the content must not remain stored at all, e.g.'
        . ' an erasure request or a review posted on the wrong product. Returns {deleted: true, id, before}'
        . ' on success.',
)]
#[Permission(resource: 'product_review', action: Action::Delete)]
final readonly class DeleteProductReview
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'product_review';

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $id,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        $path = Route::item(Resource::ProductReview, $id);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['deleted' => false, 'id' => $id]);
        }

        $shaped = ProductReviewOutput::fromPayload($current, $id);

        $preview = $this->renderDeletePreview($id, $shaped);
        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm product review deletion of #' . $id,
            'Delete this product review?',
            $preview,
            'Re-invoke with confirm: true to apply this deletion.',
        );
        if (null !== $refusal) {
            $out = ['ok' => false, 'deleted' => false, 'id' => $id] + $refusal;
            if (isset($refusal['hint'])) {
                $out['preview'] = $preview;
            }

            return $out;
        }

        try {
            $this->client->delete($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['deleted' => false, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'delete_product_review',
            targetType: self::TARGET_TYPE,
            targetCode: $id,
            beforeState: $current,
            afterState: [],
            diff: ['deleted' => ['before' => false, 'after' => true]],
            context: $context,
        );

        return [
            'ok' => true,
            'deleted' => true,
            'id' => $id,
            'before' => $shaped,
        ];
    }

    private function renderDeletePreview(string $id, ProductReviewOutput $shaped): string
    {
        return implode("\n", [
            'Delete product review #' . $id . ' on product "' . ($shaped->productCode ?? '?') . '"',
            '',
            'Rating:  ' . ($shaped->rating ?? '?') . '/5',
            'Title:   ' . ($shaped->title ?? ''),
            'Comment: ' . ($shaped->comment ?? ''),
            'Status:  ' . ($shaped->status ?? '?'),
            '',
            'The record is destroyed and the product average rating is recomputed without it.',
            'Action is NOT revertable - reject_product_review hides a review without destroying it.',
        ]);
    }
}
