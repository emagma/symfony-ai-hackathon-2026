<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Order;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Order\CancelOrder;
use Emagma\McpSyliusBundle\Tool\Order\ResendOrderConfirmationEmail;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CancelOrder::class)]
#[CoversClass(ResendOrderConfirmationEmail::class)]
final class OrderActionToolsTest extends McpToolTestCase
{
    private const string TOKEN = 'TOKEN123';

    private const string PATH = 'orders/TOKEN123';

    public function testTheToolsDeclareTheirMcpAttribute(): void
    {
        $expected = [
            CancelOrder::class => 'cancel_order',
            ResendOrderConfirmationEmail::class => 'resend_order_confirmation_email',
        ];

        foreach ($expected as $class => $name) {
            $attributes = (new \ReflectionClass($class))->getAttributes(McpTool::class);
            self::assertCount(1, $attributes);
            self::assertSame($name, $attributes[0]->newInstance()->name);
        }
    }

    public function testCancelRefusesABlankTokenWithoutTouchingTheApi(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecoded');

        self::assertSame(
            'not_found',
            $this->call(new CancelOrder($client, $this->audit()), '', confirm: true)['reason'],
        );
    }

    public function testCancelPatchesTheTransitionEndpointWithNoBody(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->order());
        $client->expects(self::once())
            ->method('patch')
            ->with(self::PATH . '/cancel', [], [])
            ->willReturn(['state' => 'cancelled', 'paymentState' => 'cancelled'] + $this->order());

        $result = $this->call(new CancelOrder($client, $this->audit()), self::TOKEN, confirm: true);

        self::assertTrue($result['cancelled']);
        self::assertSame(['state' => ['before' => 'new', 'after' => 'cancelled']], $result['diff']);
        self::assertSame('cancelled', $result['after']['state']);
    }

    /** The graph declares `new → cancelled` only; Sylius would answer without saying why. */
    public function testCancelRefusesAnOrderThatIsNotNew(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['state' => 'fulfilled'] + $this->order());
        $client->expects(self::never())->method('patch');

        $result = $this->call(new CancelOrder($client, $this->audit()), self::TOKEN, confirm: true);

        self::assertSame('not_cancellable', $result['reason']);
        self::assertSame('fulfilled', $result['state']);
    }

    public function testCancelReportsAnAlreadyCancelledOrderAsSuch(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['state' => 'cancelled'] + $this->order());

        self::assertSame(
            'already_cancelled',
            $this->call(new CancelOrder($client, $this->audit()), self::TOKEN, confirm: true)['reason'],
        );
    }

    public function testCancelWarnsInThePreviewWhenTheOrderIsPaid(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['paymentState' => 'paid'] + $this->order());

        $result = $this->call(new CancelOrder($client, $this->audit()), self::TOKEN, context: $this->noElicitContext());

        self::assertSame('human_confirmation_required', $result['reason']);
        self::assertStringContainsString('THIS ORDER IS PAID', $result['preview']);
        self::assertStringContainsString('refund_payment', $result['preview']);
    }

    public function testCancelDoesNotWarnAboutPaymentWhenNothingWasCharged(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->order());

        $result = $this->call(new CancelOrder($client, $this->audit()), self::TOKEN, context: $this->noElicitContext());

        self::assertStringNotContainsString('THIS ORDER IS PAID', $result['preview']);
    }

    /** The payload does not carry the currency subunit: dividing would be a guess. */
    public function testCancelStatesTheTotalInMinorUnits(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->order());

        $result = $this->call(new CancelOrder($client, $this->audit()), self::TOKEN, context: $this->noElicitContext());

        self::assertStringContainsString('98350 EUR (minor units)', $result['preview']);
        self::assertStringNotContainsString('983.50', $result['preview']);
    }

    public function testCancelDeclinedLeavesTheOrderAlone(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->order());
        $client->expects(self::never())->method('patch');

        $tool = new CancelOrder($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool(self::TOKEN, context: $context),
            ElicitAction::Decline,
        );

        self::assertFalse($result['cancelled']);
    }

    public function testCancelRecordsAnAuditEntryKeyedByTokenValue(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->order());
        $client->method('patch')->willReturn($this->order());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            // tokenValue already travels in shop URLs: no personal data, unlike the customer email.
            static fn (RecordedWrite $e): bool => 'cancel_order' === $e->getTool() &&
                'order' === $e->getTargetType() &&
                self::TOKEN === $e->getTargetCode(),
        ));

        $this->call(new CancelOrder($client, $audit), self::TOKEN, confirm: true);
    }

    public function testResendPostsAnEmptyBodyToTheActionEndpoint(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->order());
        // The command is built from the URI (OrderTokenValueAware).
        $client->expects(self::once())
            ->method('post')
            ->with(self::PATH . '/resend-confirmation-email', [], [])
            ->willReturn([]);

        $result = $this->call(new ResendOrderConfirmationEmail($client, $this->audit()), self::TOKEN, confirm: true);

        self::assertTrue($result['sent']);
        self::assertSame('000000042', $result['number']);
    }

    /** No shop state moves, but an email to a customer is an outward-facing act. */
    public function testResendIsAudited(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->order());
        $client->method('post')->willReturn([]);

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'resend_order_confirmation_email' === $e->getTool(),
        ));

        $this->call(new ResendOrderConfirmationEmail($client, $audit), self::TOKEN, confirm: true);
    }

    public function testResendStillGoesThroughTheConfirmationGate(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->order());
        $client->expects(self::never())->method('post');

        self::assertSame(
            'human_confirmation_required',
            $this->call(
                new ResendOrderConfirmationEmail($client, $this->audit()),
                self::TOKEN,
                context: $this->noElicitContext(),
            )['reason'],
        );
    }

    public function testResendReturnsNotFoundOn404(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));

        self::assertSame(
            'not_found',
            $this->call(new ResendOrderConfirmationEmail($client, $this->audit()), 'nope', confirm: true)['reason'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function order(): array
    {
        return [
            '@id' => '/api/v2/admin/orders/TOKEN123',
            'tokenValue' => self::TOKEN,
            'number' => '000000042',
            'state' => 'new',
            'paymentState' => 'awaiting_payment',
            'shippingState' => 'ready',
            'total' => 98350,
            'currencyCode' => 'EUR',
            'channel' => '/api/v2/admin/channels/WEB',
            'customer' => '/api/v2/admin/customers/20',
        ];
    }
}
