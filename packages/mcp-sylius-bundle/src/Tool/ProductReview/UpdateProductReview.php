<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductReview;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
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
 * The admin PUT only denormalizes `title`, `rating` and `comment`; the body carries all three,
 * since the PUT would blank an omitted one.
 *
 * @internal
 */
#[McpTool(
    name: 'update_product_review',
    description: 'Update the content of a Sylius admin product review (id from search_product_reviews). Editable:'
        . ' title, rating (1-5), comment. The author, the reviewed product and the moderation status CANNOT'
        . ' be changed here - use accept_product_review / reject_product_review for the status. This'
        . ' edits words a CUSTOMER wrote: correct a typo or strip abuse, do not rewrite their opinion.'
        . ' Returns {updated: true, id, diff, before, after} on success.',
)]
#[Permission(resource: 'product_review', action: Action::Write)]
final readonly class UpdateProductReview
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
        ?string $title = null,
        ?int $rating = null,
        ?string $comment = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        if (null === $title && null === $rating && null === $comment) {
            return ['ok' => false, 'updated' => false, 'reason' => 'no_change_requested', 'id' => $id];
        }

        $path = Route::item(Resource::ProductReview, $id);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'id' => $id]);
        }

        $requested = ['title' => $title, 'rating' => $rating, 'comment' => $comment];

        $body = [];
        $diff = [];
        foreach ($requested as $field => $value) {
            $before = $current[$field] ?? null;
            $body[$field] = $value ?? $before;

            if (null !== $value && $value !== $before) {
                $diff[$field] = ['before' => $before, 'after' => $value];
            }
        }

        if ([] === $diff) {
            return ['ok' => false, 'updated' => false, 'reason' => 'no_change', 'id' => $id];
        }

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm product review update on #' . $id,
            'Apply this product review update?',
            DiffPreview::targets('Update product review #' . $id, $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'updated' => false, 'id' => $id, 'diff' => $diff] + $refusal;
        }

        try {
            $updated = $this->client->put($path, $body);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'update_product_review',
            targetType: self::TARGET_TYPE,
            targetCode: $id,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'updated' => true,
            'id' => $id,
            'diff' => $diff,
            'before' => ProductReviewOutput::fromPayload($current, $id),
            'after' => ProductReviewOutput::fromPayload($updated, $id),
        ];
    }
}
