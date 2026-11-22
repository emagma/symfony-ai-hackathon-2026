<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductReview;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
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
 * `sylius_product_review` only allows `new → rejected`, one way. The status is checked first
 * because the vendor's 422 does not say why.
 *
 * @internal
 */
#[McpTool(
    name: 'reject_product_review',
    description: 'Reject a Sylius admin product review: it stays in the database, readable through'
        . ' get_product_reviews, but is never shown on the storefront and does not count towards the product'
        . ' average rating. Only a review whose status is "new" can be rejected -'
        . ' search_product_reviews(status: "new") is the moderation queue. IRREVERSIBLE: the state machine'
        . ' has no transition out of "rejected", so revert_mcp_edit cannot undo this. Still preferable to'
        . ' delete_product_review, which destroys the record. Returns {rejected: true, id, diff, before,'
        . ' after} on success. Tool-specific reasons: already_rejected, already_accepted.',
)]
#[Permission(resource: 'product_review', action: Action::Write)]
#[Irreversible]
final readonly class RejectProductReview
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
            return ApiFailure::describe($exception, ['rejected' => false, 'id' => $id]);
        }

        $status = \is_string($current['status'] ?? null) ? $current['status'] : null;
        if ('new' !== $status) {
            return [
                'ok' => false,
                'rejected' => false,
                'reason' => 'rejected' === $status ? 'already_rejected' : 'already_accepted',
                'id' => $id,
                'status' => $status,
            ];
        }

        $diff = ['status' => ['before' => $status, 'after' => 'rejected']];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm rejecting product review #' . $id,
            'Reject this product review?',
            $this->renderRejectPreview($id, $current),
            'Re-invoke with confirm: true to reject this review.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'rejected' => false, 'id' => $id, 'diff' => $diff] + $refusal;
        }

        try {
            // input="false": the operation ignores the body entirely.
            $updated = $this->client->patch(Route::action(Resource::ProductReview, $id, 'reject'));
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['rejected' => false, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'reject_product_review',
            targetType: self::TARGET_TYPE,
            targetCode: $id,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'rejected' => true,
            'id' => $id,
            'diff' => $diff,
            'before' => ProductReviewOutput::fromPayload($current, $id),
            'after' => ProductReviewOutput::fromPayload($updated, $id),
        ];
    }

    /**
     * @param array<string, mixed> $current
     */
    private function renderRejectPreview(string $id, array $current): string
    {
        $shaped = ProductReviewOutput::fromPayload($current, $id);

        return implode("\n", [
            'Reject product review #' . $id . ' on product "' . ($shaped->productCode ?? '?') . '"',
            '',
            'Rating:  ' . ($shaped->rating ?? '?') . '/5',
            'Title:   ' . ($shaped->title ?? ''),
            'Comment: ' . ($shaped->comment ?? ''),
            '',
            'It stays in the database but never reaches the storefront.',
            'There is no transition back - this cannot be reverted.',
        ]);
    }
}
