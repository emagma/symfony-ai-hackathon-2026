<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Order;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\Output\Order\OrderOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Cancels an order by its token: a transition that cannot be undone.
 *
 * @internal
 */
#[McpTool(
    name: 'cancel_order',
    description: 'Cancel a Sylius admin order by its tokenValue (search_orders returns it - NOT the number, NOT'
        . ' the id). Only an order whose state is "new" can be cancelled; a fulfilled one cannot.'
        . ' IRREVERSIBLE: the state machine has no transition out of "cancelled", so revert_mcp_edit cannot'
        . ' undo this and neither can the admin UI. Cancelling does NOT refund anything. If the order was'
        . ' paid, the money stays taken until you also call refund_payment on its payment - check'
        . ' paymentState first with get_orders. Returns {cancelled: true, tokenValue, diff, before, after}'
        . ' on success. Tool-specific reasons: already_cancelled, not_cancellable.',
    // A write by permission, but one with no way back: say so to the client.
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
)]
#[Permission(resource: 'order', action: Action::Write)]
#[Irreversible]
final readonly class CancelOrder
{
    use WriteConfirmationTrait;

    private const string TARGET_TYPE = 'order';

    public function __construct(
        private AdminApiClientInterface $client,
        private WriteJournalInterface $audit,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $tokenValue,
        bool $confirm = false,
        ?RequestContext $context = null,
    ): array {
        if ('' === trim($tokenValue)) {
            return ['ok' => false, 'cancelled' => false, 'reason' => 'not_found', 'tokenValue' => $tokenValue];
        }

        $path = Route::item(Resource::Order, $tokenValue);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['cancelled' => false, 'tokenValue' => $tokenValue]);
        }

        $state = \is_string($current['state'] ?? null) ? $current['state'] : null;
        if ('new' !== $state) {
            return [
                'ok' => false,
                'cancelled' => false,
                'reason' => 'cancelled' === $state ? 'already_cancelled' : 'not_cancellable',
                'tokenValue' => $tokenValue,
                'state' => $state,
            ];
        }

        $diff = ['state' => ['before' => $state, 'after' => 'cancelled']];
        $shaped = OrderOutput::fromPayload($current, $tokenValue);

        $preview = $this->renderCancelPreview($tokenValue, $shaped);
        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm cancelling order ' . ($shaped->number ?? $tokenValue),
            'Cancel this order?',
            $preview,
            'Re-invoke with confirm: true to cancel it.',
        );
        if (null !== $refusal) {
            $out = ['ok' => false, 'cancelled' => false, 'tokenValue' => $tokenValue, 'diff' => $diff] + $refusal;
            if (isset($refusal['hint'])) {
                // The total and the payment state are only in the preview: a client that cannot elicit still gets it.
                $out['preview'] = $preview;
            }

            return $out;
        }

        try {
            // input="false": the operation ignores the body entirely.
            $updated = $this->client->patch(Route::action(Resource::Order, $tokenValue, 'cancel'));
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['cancelled' => false, 'tokenValue' => $tokenValue]);
        }

        $this->audit->record(
            tool: 'cancel_order',
            targetType: self::TARGET_TYPE,
            // tokenValue carries no personal data, unlike the email.
            targetCode: $tokenValue,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'cancelled' => true,
            'tokenValue' => $tokenValue,
            'diff' => $diff,
            'before' => $shaped,
            'after' => OrderOutput::fromPayload($updated, $tokenValue),
        ];
    }

    private function renderCancelPreview(string $tokenValue, OrderOutput $shaped): string
    {
        // Minor units, undivided: the payload does not carry the currency's subunit, so dividing would be a guess.
        $total = $shaped->total;
        $money = \is_int($total)
            ? $total . ' ' . ($shaped->currencyCode ?? '') . ' (minor units)'
            : 'unknown total';

        $lines = [
            'Cancel order ' . ($shaped->number ?? $tokenValue),
            '',
            'Total:    ' . $money,
            'Payment:  ' . ($shaped->paymentState ?? '?'),
            'Shipping: ' . ($shaped->shippingState ?? '?'),
            '',
            'There is no transition out of "cancelled" - this cannot be undone.',
        ];

        if ('paid' === ($shaped->paymentState)) {
            $lines[] = 'THIS ORDER IS PAID. Cancelling does not refund it; refund_payment does.';
        }

        return implode("\n", $lines);
    }
}
