<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Payment;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Journal\Irreversible;
use Emagma\McpCoreBundle\Journal\WriteJournalInterface;
use Emagma\McpCoreBundle\WriteConfirmation\WriteConfirmationTrait;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpSyliusBundle\AdminApi\ApiFeature;
use Emagma\McpSyliusBundle\AdminApi\Gate\RequiresApiFeature;
use Emagma\McpSyliusBundle\Output\Payment\PaymentOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Refunds a completed payment for its full amount: a transition that cannot be undone.
 *
 * @internal
 */
#[McpTool(
    name: 'refund_payment',
    description: 'Mark a Sylius admin payment as REFUNDED (id from search_payments or list_order_collection(kind: payments)). Only a'
        . ' payment in state "completed" can be refunded, and always for the FULL amount - the Sylius'
        . ' payment state machine has no notion of a partial refund. IMPORTANT: this records the refund in'
        . ' Sylius. Whether the money actually travels back depends on the payment gateway reacting to the'
        . ' transition. With an offline method, or a gateway whose refunds are issued from its own'
        . ' back-office, this marks the order refunded while the customer has been given nothing - check'
        . ' how the shop handles the method before relying on it. IRREVERSIBLE: "refunded" has no way out'
        . ' in the state machine. Returns {refunded: true, id, diff, before, after} on success.'
        . ' Tool-specific reasons: already_refunded, not_refundable.',
    // A write by permission, but one with no way back: say so to the client.
    annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false, openWorldHint: false),
)]
#[Permission(resource: 'payment', action: Action::Write)]
#[Irreversible]
#[RequiresApiFeature(ApiFeature::PaymentRefund)]
final readonly class RefundPayment
{
    use WriteConfirmationTrait;
    use PaymentAmountTrait;

    private const string TARGET_TYPE = 'payment';

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
            return ['ok' => false, 'refunded' => false, 'reason' => 'not_found', 'id' => $id];
        }

        $path = Route::item(Resource::Payment, $id);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['refunded' => false, 'id' => $id]);
        }

        $state = \is_string($current['state'] ?? null) ? $current['state'] : null;
        if ('completed' !== $state) {
            return [
                'ok' => false,
                'refunded' => false,
                'reason' => 'refunded' === $state ? 'already_refunded' : 'not_refundable',
                'id' => $id,
                'state' => $state,
            ];
        }

        $diff = ['state' => ['before' => $state, 'after' => 'refunded']];
        $shaped = PaymentOutput::fromPayload($current);

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm refunding payment #' . $id,
            'Refund this payment in full?',
            implode("\n", [
                'Refund payment #' . $id . ' IN FULL',
                '',
                'Amount:  ' . self::describeAmount($shaped),
                'Method:  ' . ($shaped->methodCode ?? '?'),
                'Order:   ' . ($shaped->orderTokenValue ?? '?'),
                '',
                'Partial refunds are not possible here - the whole amount goes.',
                'Whether money actually returns depends on the gateway reacting to this;',
                'with an offline method nothing moves and only Sylius is updated.',
                'There is no transition out of "refunded".',
            ]),
            'Re-invoke with confirm: true to refund it.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'refunded' => false, 'id' => $id, 'diff' => $diff] + $refusal;
        }

        try {
            // input="false": the operation ignores the body entirely.
            $updated = $this->client->patch(Route::action(Resource::Payment, $id, 'refund'));
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['refunded' => false, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'refund_payment',
            targetType: self::TARGET_TYPE,
            targetCode: $id,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'refunded' => true,
            'id' => $id,
            'diff' => $diff,
            'before' => $shaped,
            'after' => PaymentOutput::fromPayload($updated),
        ];
    }
}
