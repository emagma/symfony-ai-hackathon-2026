<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ShippingMethod;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\ShippingMethod\ShippingMethodOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * NonArchivedExtension only guards collections: the item GET reads an archived method by code.
 *
 * @internal
 */
#[McpTool(
    name: 'restore_shipping_method',
    description: 'Restore a previously archived Sylius admin shipping method, putting it back into service. Find'
        . ' archived methods with search_shipping_methods(archived: true). Returns {restored: true, code,'
        . ' diff, before, after} on success. Tool-specific reasons: not_archived.',
)]
#[Permission(resource: 'shipping_method', action: Action::Write)]
#[Irreversible]
final readonly class RestoreShippingMethod
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'shipping_method';

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
        $path = Route::item(Resource::ShippingMethod, $code);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['restored' => false, 'code' => $code]);
        }

        if (null === ($current['archivedAt'] ?? null)) {
            return ['ok' => false, 'restored' => false, 'reason' => 'not_archived', 'code' => $code];
        }

        $diff = ['archived' => ['before' => true, 'after' => false]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm restoring shipping method "' . $code . '"',
            'Restore this shipping method?',
            DiffPreview::targets('Restore shipping method "' . $code . '"', $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'restored' => false, 'code' => $code, 'diff' => $diff] + $refusal;
        }

        try {
            // input="false": the operation ignores the body entirely.
            $updated = $this->client->patch(Route::action(Resource::ShippingMethod, $code, 'restore'));
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['restored' => false, 'code' => $code]);
        }

        $this->audit->record(
            tool: 'restore_shipping_method',
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
            'before' => ShippingMethodOutput::fromPayload($current, $locale, $code),
            'after' => ShippingMethodOutput::fromPayload($updated, $locale, $code),
        ];
    }
}
