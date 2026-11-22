<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Shipment;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\Shipment\ShipmentOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Unlike the `input="false"` state actions, this PATCH carries a body (`trackingCode`).
 * Shipping emails the customer, and no `update_shipment` can fix the tracking code afterwards.
 *
 * @internal
 */
#[McpTool(
    name: 'ship_shipment',
    description: 'Mark a Sylius admin shipment as SHIPPED (id from search_shipments or list_order_collection(kind: shipments)),'
        . ' optionally recording trackingCode. Only a shipment in state "ready" can be shipped. This sends'
        . ' the shipment confirmation email to the customer, so calling it on the wrong shipment cannot be'
        . ' fully undone - and there is no tool to edit the tracking code afterwards, so pass the right'
        . ' one now. IRREVERSIBLE: the state machine has no transition out of "shipped". Returns {shipped:'
        . ' true, id, diff, before, after} on success. Tool-specific reasons: already_shipped,'
        . ' not_shippable.',
    // A write by permission, but one with no way back (and an email sent): say so to the client.
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
)]
#[Permission(resource: 'shipment', action: Action::Write)]
#[Irreversible]
final readonly class ShipShipment
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'shipment';

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
        ?string $trackingCode = null,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        if ('' === trim($id)) {
            return ['ok' => false, 'shipped' => false, 'reason' => 'not_found', 'id' => $id];
        }

        $path = Route::item(Resource::Shipment, $id);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['shipped' => false, 'id' => $id]);
        }

        $state = \is_string($current['state'] ?? null) ? $current['state'] : null;
        if ('ready' !== $state) {
            return [
                'ok' => false,
                'shipped' => false,
                'reason' => 'shipped' === $state ? 'already_shipped' : 'not_shippable',
                'id' => $id,
                'state' => $state,
            ];
        }

        $trackingCode = null !== $trackingCode && '' !== trim($trackingCode) ? trim($trackingCode) : null;

        $diff = ['state' => ['before' => $state, 'after' => 'shipped']];
        if (null !== $trackingCode) {
            $diff['tracking'] = ['before' => $current['tracking'] ?? null, 'after' => $trackingCode];
        }

        $shaped = ShipmentOutput::fromPayload($current);

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm shipping shipment #' . $id,
            'Mark this shipment as shipped?',
            implode("\n", [
                'Ship shipment #' . $id,
                '',
                'Method:   ' . ($shaped->methodCode ?? '?'),
                'Order:    ' . ($shaped->orderTokenValue ?? '?'),
                'Units:    ' . ($shaped->unitsCount ?? 0),
                'Tracking: ' . ($trackingCode ?? 'none'),
                '',
                'The customer receives the shipment confirmation email immediately.',
                'There is no transition out of "shipped", and no tool to fix the tracking code later.',
            ]),
            'Re-invoke with confirm: true to ship it.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'shipped' => false, 'id' => $id, 'diff' => $diff] + $refusal;
        }

        try {
            $updated = $this->client->patch(
                Route::action(Resource::Shipment, $id, 'ship'),
                null !== $trackingCode ? ['trackingCode' => $trackingCode] : [],
            );
        } catch (ClientException $exception) {
            // Sylius answers 500, not 422, for a shipment that is not `ready`: the state check above
            // rules that out, so a 500 reaching here is a genuine server fault.
            return ApiFailure::describe($exception, ['shipped' => false, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'ship_shipment',
            targetType: self::TARGET_TYPE,
            targetCode: $id,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'shipped' => true,
            'id' => $id,
            'diff' => $diff,
            'before' => $shaped,
            'after' => ShipmentOutput::fromPayload($updated),
        ];
    }
}
