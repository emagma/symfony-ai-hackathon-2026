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
use Mcp\Server\RequestContext;
use Symfony\Component\HttpClient\Exception\ClientException;

/**
 * The recipient is not an argument on purpose: sending the shop's mail to an arbitrary address would be a phishing primitive.
 * No shop state moves, yet it is confirmed: the email cannot be taken back.
 *
 * @internal
 */
#[McpTool(
    name: 'resend_order_confirmation_email',
    description: 'Send the shop\'s standard order confirmation email again for a Sylius admin order, identified by'
        . ' its tokenValue (search_orders returns it). ONLY for a customer who says they never received their'
        . ' confirmation. It carries no text of yours and cannot: it is NOT a way to contact a customer about'
        . ' a delay, a stock issue or anything else - draft such a message in your reply instead. The'
        . ' recipient is the address stored on the order and CANNOT be changed here. Nothing in the shop'
        . ' changes state, but the email leaves immediately and cannot be recalled; each call is another'
        . ' message in someone\'s inbox. Returns {sent: true, tokenValue} on success.',
)]
#[Permission(resource: 'order', action: Action::Write)]
#[Irreversible]
final readonly class ResendOrderConfirmationEmail
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
            return ['ok' => false, 'sent' => false, 'reason' => 'not_found', 'tokenValue' => $tokenValue];
        }

        $path = Route::item(Resource::Order, $tokenValue);

        try {
            $current = $this->client->getDecoded($path);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['sent' => false, 'tokenValue' => $tokenValue]);
        }

        $shaped = OrderOutput::fromPayload($current, $tokenValue);

        $preview = implode("\n", [
            'Resend the confirmation email for order ' . ($shaped->number ?? $tokenValue),
            '',
            'The recipient is the address on the order; it cannot be changed here.',
            'The message leaves immediately and cannot be recalled.',
        ]);

        $refusal = $this->confirmWrite(
            $context,
            $confirm,
            'Confirm resending the confirmation email for order ' . ($shaped->number ?? $tokenValue),
            'Send this email again?',
            $preview,
            'Re-invoke with confirm: true to send it.',
        );
        if (null !== $refusal) {
            $out = ['ok' => false, 'sent' => false, 'tokenValue' => $tokenValue] + $refusal;
            if (isset($refusal['hint'])) {
                $out['preview'] = $preview;
            }

            return $out;
        }

        try {
            // The command is built from the URI (OrderTokenValueAware): the body carries nothing.
            $this->client->post(Route::action(Resource::Order, $tokenValue, 'resend-confirmation-email'), []);
        } catch (ClientException $exception) {
            return ApiFailure::describe($exception, ['sent' => false, 'tokenValue' => $tokenValue]);
        }

        // Recorded although no shop state moved: an email to a customer is an outward-facing act.
        $this->audit->record(
            tool: 'resend_order_confirmation_email',
            targetType: self::TARGET_TYPE,
            targetCode: $tokenValue,
            beforeState: [],
            afterState: [],
            diff: ['confirmationEmailResent' => ['before' => false, 'after' => true]],
            context: $context,
        );

        return ['ok' => true, 'sent' => true, 'tokenValue' => $tokenValue, 'number' => $shaped->number];
    }
}
