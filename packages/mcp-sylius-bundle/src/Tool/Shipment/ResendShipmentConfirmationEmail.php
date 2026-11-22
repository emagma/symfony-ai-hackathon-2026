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
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * No shop state moves, but an email cannot be recalled: it still passes the confirmation gate.
 *
 * @internal
 */
#[McpTool(
    name: 'resend_shipment_confirmation_email',
    description: 'Send the shipment confirmation email again for a Sylius admin shipment (id from search_shipments'
        . ' or list_order_collection(kind: shipments)). Use when a customer says they never got their tracking details. Only'
        . ' a shipment already in state "shipped" has a confirmation to resend. The recipient is the'
        . ' address on the order and CANNOT be changed here. Nothing in the shop changes state, but the'
        . ' email leaves immediately and cannot be recalled. Returns {sent: true, id} on success.'
        . ' Tool-specific reasons: not_shipped.',
)]
#[Permission(resource: 'shipment', action: Action::Write)]
#[Irreversible]
final readonly class ResendShipmentConfirmationEmail
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
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        if ('' === trim($id)) {
            return ['ok' => false, 'sent' => false, 'reason' => 'not_found', 'id' => $id];
        }

        $path = Route::item(Resource::Shipment, $id);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['sent' => false, 'id' => $id]);
        }

        $state = \is_string($current['state'] ?? null) ? $current['state'] : null;
        if ('shipped' !== $state) {
            return ['ok' => false, 'sent' => false, 'reason' => 'not_shipped', 'id' => $id, 'state' => $state];
        }

        $shaped = ShipmentOutput::fromPayload($current);

        $preview = implode("\n", [
            'Resend the shipment confirmation email for shipment #' . $id,
            '',
            'Order:    ' . ($shaped->orderTokenValue ?? '?'),
            'Tracking: ' . ($shaped->tracking ?? 'none'),
            '',
            'The recipient is the address on the order; it cannot be changed here.',
            'The message leaves immediately and cannot be recalled.',
        ]);

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm resending the shipment confirmation email for shipment #' . $id,
            'Send this email again?',
            $preview,
            'Re-invoke with confirm: true to send it.',
        );
        if (null !== $refusal) {
            $out = ['ok' => false, 'sent' => false, 'id' => $id] + $refusal;
            if (isset($refusal['hint'])) {
                $out['preview'] = $preview;
            }

            return $out;
        }

        try {
            // The command is built from the URI (ShipmentIdAware): the body carries nothing.
            $this->client->post(Route::action(Resource::Shipment, $id, 'resend-confirmation-email'), []);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['sent' => false, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'resend_shipment_confirmation_email',
            targetType: self::TARGET_TYPE,
            targetCode: $id,
            beforeState: [],
            afterState: [],
            diff: ['confirmationEmailResent' => ['before' => false, 'after' => true]],
            context: $context,
        );

        return ['ok' => true, 'sent' => true, 'id' => $id, 'orderTokenValue' => $shaped->orderTokenValue];
    }
}
