<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Promotion;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\Promotion\PromotionOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Archives a cart promotion: it stops discounting, and restore_promotion undoes it.
 *
 * @internal
 */
#[McpTool(
    name: 'archive_promotion',
    description: 'Archive a Sylius admin cart promotion: it stops applying at checkout while past orders keep'
        . ' their discounts and their reference to it. Reversible via restore_promotion - prefer this'
        . ' over delete_promotions, which is permanent. Archived promotions are hidden from search_promotions'
        . ' unless you pass archived: true. Returns {archived: true, code, diff, before, after} on success.'
        . ' Tool-specific reasons: already_archived.',
)]
#[Permission(resource: 'promotion', action: Action::Write)]
#[Irreversible]
final readonly class ArchivePromotion
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'promotion';

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
        private string $defaultLocale,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $code,
        ?string $locale = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        $locale ??= $this->defaultLocale;
        $path = Route::item(Resource::Promotion, $code);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['archived' => false, 'code' => $code]);
        }

        if (null !== ($current['archivedAt'] ?? null)) {
            return [
                'ok' => false,
                'archived' => false,
                'reason' => 'already_archived',
                'code' => $code,
                'archivedAt' => $current['archivedAt'],
            ];
        }

        $diff = ['archived' => ['before' => false, 'after' => true]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm archiving promotion "' . $code . '"',
            'Archive this promotion?',
            DiffPreview::targets('Archive promotion "' . $code . '"', $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'archived' => false, 'code' => $code, 'diff' => $diff] + $refusal;
        }

        try {
            // input="false": the operation ignores the body entirely.
            $updated = $this->client->patch(Route::action(Resource::Promotion, $code, 'archive'));
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['archived' => false, 'code' => $code]);
        }

        $this->audit->record(
            tool: 'archive_promotion',
            targetType: self::TARGET_TYPE,
            targetCode: $code,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'archived' => true,
            'code' => $code,
            'diff' => $diff,
            'before' => PromotionOutput::fromPayload($current, $locale, $code),
            'after' => PromotionOutput::fromPayload($updated, $locale, $code),
        ];
    }
}
