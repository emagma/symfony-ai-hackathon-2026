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
 * Archives a shipping method: it leaves the checkout, and restore_shipping_method undoes it.
 *
 * @internal
 */
#[McpTool(
    name: 'archive_shipping_method',
    description: 'Archive a Sylius admin shipping method: it stops being offered at checkout while past shipments'
        . ' keep their reference. Reversible via restore_shipping_method - prefer this over'
        . ' delete_shipping_methods, which is permanent and not revertable. Archived methods are hidden from'
        . ' search_shipping_methods unless you pass archived: true. Returns {archived: true, code, diff,'
        . ' before, after} on success. Tool-specific reasons: already_archived.',
)]
#[Permission(resource: 'shipping_method', action: Action::Write)]
#[Irreversible]
final readonly class ArchiveShippingMethod
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
            'Confirm archiving shipping method "' . $code . '"',
            'Archive this shipping method?',
            DiffPreview::targets('Archive shipping method "' . $code . '"', $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'archived' => false, 'code' => $code, 'diff' => $diff] + $refusal;
        }

        try {
            // input="false": the operation ignores the body entirely.
            $updated = $this->client->patch(Route::action(Resource::ShippingMethod, $code, 'archive'));
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['archived' => false, 'code' => $code]);
        }

        $this->audit->record(
            tool: 'archive_shipping_method',
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
            'before' => ShippingMethodOutput::fromPayload($current, $locale, $code),
            'after' => ShippingMethodOutput::fromPayload($updated, $locale, $code),
        ];
    }
}
