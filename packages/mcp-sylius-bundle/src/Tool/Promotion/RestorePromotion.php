<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Promotion;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
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
 * Not a visibility toggle: a restored promotion applies again at once if its schedule covers today.
 *
 * @internal
 */
#[McpTool(
    name: 'restore_promotion',
    description: 'Restore an archived Sylius admin cart promotion so it applies at checkout again. Find archived'
        . ' promotions with search_promotions(archived: true). NOTE: if the promotion schedule (startsAt /'
        . ' endsAt) still covers today, it starts discounting orders again as soon as this returns -'
        . ' check the dates first, or set new ones with upsert_promotions. Returns {restored: true, code,'
        . ' diff, before, after} on success. Tool-specific reasons: not_archived.',
)]
#[Permission(resource: 'promotion', action: Action::Write)]
#[Irreversible]
final readonly class RestorePromotion
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
            return ApiFailure::describe($exception, ['restored' => false, 'code' => $code]);
        }

        if (null === ($current['archivedAt'] ?? null)) {
            return ['ok' => false, 'restored' => false, 'reason' => 'not_archived', 'code' => $code];
        }

        $diff = ['archived' => ['before' => true, 'after' => false]];

        $shaped = PromotionOutput::fromPayload($current, $locale, $code);
        /** @var list<string> $channelCodes */
        $channelCodes = $shaped->channels;

        $preview = implode("\n", [
            'Restore promotion "' . $code . '" (' . ($shaped->name ?? '') . ')',
            '',
            'Schedule: ' . ($shaped->startsAt ?? 'no start') . ' → ' . ($shaped->endsAt ?? 'no end'),
            'Channels: ' . ([] === $channelCodes ? 'none' : implode(', ', $channelCodes)),
            '',
            'If the schedule covers today, it starts discounting orders again immediately.',
        ]);

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm restoring promotion "' . $code . '"',
            'Restore this promotion?',
            $preview,
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'restored' => false, 'code' => $code, 'diff' => $diff] + $refusal;
        }

        try {
            // input="false": the operation ignores the body entirely.
            $updated = $this->client->patch(Route::action(Resource::Promotion, $code, 'restore'));
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['restored' => false, 'code' => $code]);
        }

        $this->audit->record(
            tool: 'restore_promotion',
            targetType: self::TARGET_TYPE,
            targetCode: $code,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'restored' => true,
            'code' => $code,
            'diff' => $diff,
            'before' => $shaped,
            'after' => PromotionOutput::fromPayload($updated, $locale, $code),
        ];
    }
}
