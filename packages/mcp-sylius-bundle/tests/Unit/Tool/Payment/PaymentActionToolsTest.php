<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Payment;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Payment\CompletePayment;
use Emagma\McpSyliusBundle\Tool\Payment\RefundPayment;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(CompletePayment::class)]
#[CoversClass(RefundPayment::class)]
final class PaymentActionToolsTest extends McpToolTestCase
{
    private const string PATH = 'payments/7';

    public function testTheToolsDeclareTheirMcpAttribute(): void
    {
        $expected = [
            CompletePayment::class => 'complete_payment',
            RefundPayment::class => 'refund_payment',
        ];

        foreach ($expected as $class => $name) {
            $attributes = (new \ReflectionClass($class))->getAttributes(McpTool::class);
            self::assertCount(1, $attributes);
            self::assertSame($name, $attributes[0]->newInstance()->name);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function completableStateProvider(): array
    {
        // Mirrors the `complete` transition of the sylius_payment graph.
        return ['new' => ['new'], 'processing' => ['processing'], 'authorized' => ['authorized']];
    }

    #[DataProvider('completableStateProvider')]
    public function testCompleteAcceptsEveryStateTheGraphAllows(string $state): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['state' => $state] + $this->payment());
        $client->expects(self::once())
            ->method('patch')
            ->with(self::PATH . '/complete', [], [])
            ->willReturn(['state' => 'completed'] + $this->payment());

        $result = $this->call(new CompletePayment($client, $this->audit()), '7', confirm: true);

        self::assertTrue($result['completed']);
        self::assertSame(['state' => ['before' => $state, 'after' => 'completed']], $result['diff']);
    }

    public function testCompleteRefusesAnAlreadyCompletedPayment(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['state' => 'completed'] + $this->payment());
        $client->expects(self::never())->method('patch');

        self::assertSame(
            'already_completed',
            $this->call(new CompletePayment($client, $this->audit()), '7', confirm: true)['reason'],
        );
    }

    public function testCompleteRefusesAFailedPayment(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['state' => 'failed'] + $this->payment());
        $client->expects(self::never())->method('patch');

        $result = $this->call(new CompletePayment($client, $this->audit()), '7', confirm: true);

        self::assertSame('not_completable', $result['reason']);
        self::assertSame('failed', $result['state']);
    }

    public function testRefundOnlyAcceptsACompletedPayment(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->payment());
        $client->expects(self::once())
            ->method('patch')
            ->with(self::PATH . '/refund', [], [])
            ->willReturn(['state' => 'refunded'] + $this->payment());

        $result = $this->call(new RefundPayment($client, $this->audit()), '7', confirm: true);

        self::assertTrue($result['refunded']);
        self::assertSame('refunded', $result['after']['state']);
    }

    public function testRefundRefusesAPaymentThatWasNeverCompleted(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['state' => 'new'] + $this->payment());
        $client->expects(self::never())->method('patch');

        self::assertSame(
            'not_refundable',
            $this->call(new RefundPayment($client, $this->audit()), '7', confirm: true)['reason'],
        );
    }

    public function testRefundReportsAnAlreadyRefundedPaymentAsSuch(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['state' => 'refunded'] + $this->payment());

        self::assertSame(
            'already_refunded',
            $this->call(new RefundPayment($client, $this->audit()), '7', confirm: true)['reason'],
        );
    }

    public function testBothToolsRefuseABlankIdWithoutTouchingTheApi(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecoded');

        $audit = $this->audit();
        self::assertSame('not_found', $this->call(new CompletePayment($client, $audit), '', confirm: true)['reason']);
        self::assertSame('not_found', $this->call(new RefundPayment($client, $audit), '', confirm: true)['reason']);
    }

    public function testBothToolsReturnNotFoundOn404(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(404));

        $audit = $this->audit();
        self::assertSame('not_found', $this->call(new CompletePayment($client, $audit), '999', confirm: true)['reason']);
        self::assertSame('not_found', $this->call(new RefundPayment($client, $audit), '999', confirm: true)['reason']);
    }

    public function testRefundRecordsAnAuditEntry(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->payment());
        $client->method('patch')->willReturn(['state' => 'refunded'] + $this->payment());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'refund_payment' === $e->getTool() &&
                'payment' === $e->getTargetType() &&
                '7' === $e->getTargetCode(),
        ));

        $this->call(new RefundPayment($client, $audit), '7', confirm: true);
    }

    public function testRefundRefusesWithoutElicitation(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->payment());
        $client->expects(self::never())->method('patch');

        self::assertSame(
            'human_confirmation_required',
            $this->call(new RefundPayment($client, $this->audit()), '7', context: $this->noElicitContext())['reason'],
        );
    }

    public function testCompleteRefusesWithoutElicitation(): void
    {
        $client = $this->apiClient();
        // A state the graph lets through, so the gate is what stops it.
        $client->method('getDecoded')->willReturn(['state' => 'new'] + $this->payment());
        $client->expects(self::never())->method('patch');

        self::assertSame(
            'human_confirmation_required',
            $this->call(new CompletePayment($client, $this->audit()), '7', context: $this->noElicitContext())['reason'],
        );
    }

    public function testElicitationAcceptedProceedsToRefund(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->payment());
        $client->expects(self::once())->method('patch')->willReturn($this->payment());

        $tool = new RefundPayment($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('7', context: $context),
            ElicitAction::Accept,
            ['confirm' => true],
        );

        self::assertTrue($result['refunded']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(): array
    {
        return [
            '@id' => '/api/v2/admin/payments/7',
            'id' => 7,
            'state' => 'completed',
            'amount' => 13358,
            'currencyCode' => 'EUR',
            'method' => '/api/v2/admin/payment-methods/cash_on_delivery',
            'order' => '/api/v2/admin/orders/TOKEN123',
        ];
    }
}
