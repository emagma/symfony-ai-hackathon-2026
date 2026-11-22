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
 * `sylius_product_review` only allows `new → accepted`, one way: not revertable. The status is
 * checked first because the vendor's 422 does not say why.
 *
 * @internal
 */
#[McpTool(
    name: 'accept_product_review',
    description: 'Accept (publish) a Sylius admin product review: it becomes visible on the storefront and starts'
        . ' counting towards the product average rating. Only a review whose status is "new" can be'
        . ' accepted - search_product_reviews(status: "new") is the moderation queue. IRREVERSIBLE: the'
        . ' state machine has no transition out of "accepted", so revert_mcp_edit cannot undo this. Read'
        . ' the comment first. Returns {accepted: true, id, diff, before, after} on success. Tool-specific'
        . ' reasons: already_accepted, already_rejected.',
)]
#[Permission(resource: 'product_review', action: Action::Write)]
#[Irreversible]
final readonly class AcceptProductReview
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
            return ApiFailure::describe($exception, ['accepted' => false, 'id' => $id]);
        }

        $status = \is_string($current['status'] ?? null) ? $current['status'] : null;
        if ('new' !== $status) {
            return [
                'ok' => false,
                'accepted' => false,
                'reason' => 'accepted' === $status ? 'already_accepted' : 'already_rejected',
                'id' => $id,
                'status' => $status,
            ];
        }

        $diff = ['status' => ['before' => $status, 'after' => 'accepted']];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm publishing product review #' . $id,
            'Publish this product review?',
            $this->renderAcceptPreview($id, $current),
            'Re-invoke with confirm: true to publish this review.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'accepted' => false, 'id' => $id, 'diff' => $diff] + $refusal;
        }

        try {
            // input="false": the operation ignores the body entirely.
            $updated = $this->client->patch(Route::action(Resource::ProductReview, $id, 'accept'));
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['accepted' => false, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'accept_product_review',
            targetType: self::TARGET_TYPE,
            targetCode: $id,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'accepted' => true,
            'id' => $id,
            'diff' => $diff,
            'before' => ProductReviewOutput::fromPayload($current, $id),
            'after' => ProductReviewOutput::fromPayload($updated, $id),
        ];
    }

    /**
     * @param array<string, mixed> $current
     */
    private function renderAcceptPreview(string $id, array $current): string
    {
        $shaped = ProductReviewOutput::fromPayload($current, $id);

        return implode("\n", [
            'Publish product review #' . $id . ' on product "' . ($shaped->productCode ?? '?') . '"',
            '',
            'Rating:  ' . ($shaped->rating ?? '?') . '/5',
            'Title:   ' . ($shaped->title ?? ''),
            'Comment: ' . ($shaped->comment ?? ''),
            '',
            'It becomes visible on the storefront and counts towards the product average rating.',
            'There is no transition back - this cannot be reverted.',
        ]);
    }
}
