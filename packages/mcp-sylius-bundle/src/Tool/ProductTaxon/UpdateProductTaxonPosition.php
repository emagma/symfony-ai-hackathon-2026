<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\ProductTaxon;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\DiffPreview;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\ProductTaxon\ProductTaxonOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Position is the only field the admin `product_taxon:update` group accepts.
 *
 * @internal
 */
#[McpTool(
    name: 'update_product_taxon_position',
    description: 'Move a product within a taxon by setting the position of its attachment. Takes the attachment'
        . ' `id` from search_product_taxons - NOT a product or taxon code. position is the only editable'
        . ' field; to attach or detach a product use update_product_taxons. Positions are not renumbered'
        . ' for you: setting two attachments to the same position leaves their relative order to the'
        . ' database, so read the current order with search_product_taxons(taxon=) first. Returns {updated:'
        . ' true, id, diff, before, after} on success.',
)]
#[Permission(resource: 'product', action: Action::Write)]
#[Permission(resource: 'taxon', action: Action::Read)]
final readonly class UpdateProductTaxonPosition
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'product_taxon';

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
        int $position,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        $path = Route::item(Resource::ProductTaxon, $id);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'id' => $id]);
        }

        $before = ProductTaxonOutput::fromPayload($current);
        $currentPosition = $before->position;

        if ($currentPosition === $position) {
            return ['ok' => false, 'updated' => false, 'reason' => 'no_change', 'id' => $id];
        }

        $diff = ['position' => ['before' => $currentPosition, 'after' => $position]];

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm product position change on attachment #' . $id,
            'Move this product within its taxon?',
            DiffPreview::targets(sprintf(
                'Move "%s" in taxon "%s"',
                $before->productCode ?? '?',
                $before->taxonCode ?? '?',
            ), $diff),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'updated' => false, 'id' => $id, 'diff' => $diff] + $refusal;
        }

        $body = $current;
        $body['position'] = $position;

        try {
            $updated = $this->client->put($path, $body);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['updated' => false, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'update_product_taxon_position',
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
            'before' => $before,
            'after' => ProductTaxonOutput::fromPayload($updated),
        ];
    }
}
