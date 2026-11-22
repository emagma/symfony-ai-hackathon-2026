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
use Emagma\McpSyliusBundle\Output\Payment\PaymentOutput;
use Emagma\McpSyliusBundle\Tool\Shared\ApiFailure;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * Marks a payment as completed: a transition that cannot be undone.
 *
 * @internal
 */
#[McpTool(
    name: 'complete_payment',
    description: 'Mark a Sylius admin payment as COMPLETED (id from search_payments or list_order_collection(kind: payments)). This'
        . ' does NOT charge anyone: it records that the shop considers the money received, which is what an'
        . ' operator does after a bank transfer or a cash payment. On an order nobody paid, this marks it'
        . ' paid and makes it fulfillable. Only a payment in state new, processing or authorized can be'
        . ' completed. IRREVERSIBLE in the sense that the only way out is refund_payment, which is itself'
        . ' final. Returns {completed: true, id, diff, before, after} on success. Tool-specific reasons:'
        . ' already_completed, not_completable.',
)]
#[Permission(resource: 'payment', action: Action::Write)]
#[Irreversible]
final readonly class CompletePayment
{
    use WriteConfirmationTrait;
    use PaymentAmountTrait;

    private const string TARGET_TYPE = 'payment';

    /** Mirrors the `complete` transition of the sylius_payment graph. */
    private const array COMPLETABLE_FROM = [
        'new',
        'processing',
        'authorized',
    ];

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
            return ['ok' => false, 'completed' => false, 'reason' => 'not_found', 'id' => $id];
        }

        $path = Route::item(Resource::Payment, $id);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['completed' => false, 'id' => $id]);
        }

        $state = \is_string($current['state'] ?? null) ? $current['state'] : null;
        if (!\in_array($state, self::COMPLETABLE_FROM, true)) {
            return [
                'ok' => false,
                'completed' => false,
                'reason' => 'completed' === $state ? 'already_completed' : 'not_completable',
                'id' => $id,
                'state' => $state,
            ];
        }

        $diff = ['state' => ['before' => $state, 'after' => 'completed']];
        $shaped = PaymentOutput::fromPayload($current);

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm marking payment #' . $id . ' as completed',
            'Mark this payment as received?',
            implode("\n", [
                'Mark payment #' . $id . ' as COMPLETED',
                '',
                'Amount:  ' . self::describeAmount($shaped),
                'Method:  ' . ($shaped->methodCode ?? '?'),
                'Order:   ' . ($shaped->orderTokenValue ?? '?'),
                '',
                'No money moves. This records that the shop considers it received,',
                'marks the order paid and makes it fulfillable.',
            ]),
            'Re-invoke with confirm: true to apply this change.',
        );
        if (null !== $refusal) {
            return ['ok' => false, 'completed' => false, 'id' => $id, 'diff' => $diff] + $refusal;
        }

        try {
            // input="false": the operation ignores the body entirely.
            $updated = $this->client->patch(Route::action(Resource::Payment, $id, 'complete'));
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['completed' => false, 'id' => $id]);
        }

        $this->audit->record(
            tool: 'complete_payment',
            targetType: self::TARGET_TYPE,
            targetCode: $id,
            beforeState: $current,
            afterState: $updated,
            diff: $diff,
            context: $context,
        );

        return [
            'ok' => true,
            'completed' => true,
            'id' => $id,
            'diff' => $diff,
            'before' => $shaped,
            'after' => PaymentOutput::fromPayload($updated),
        ];
    }
}
