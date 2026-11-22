<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Shipment;

use Emagma\McpCoreBundle\Testing\RecordedWrite;
use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Shipment\ResendShipmentConfirmationEmail;
use Emagma\McpSyliusBundle\Tool\Shipment\ShipShipment;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Enum\ElicitAction;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ShipShipment::class)]
#[CoversClass(ResendShipmentConfirmationEmail::class)]
final class ShipmentActionToolsTest extends McpToolTestCase
{
    private const string PATH = 'shipments/4';

    public function testTheToolsDeclareTheirMcpAttribute(): void
    {
        $expected = [
            ShipShipment::class => 'ship_shipment',
            ResendShipmentConfirmationEmail::class => 'resend_shipment_confirmation_email',
        ];

        foreach ($expected as $class => $name) {
            $attributes = (new \ReflectionClass($class))->getAttributes(McpTool::class);
            self::assertCount(1, $attributes);
            self::assertSame($name, $attributes[0]->newInstance()->name);
        }
    }

    /** Unlike the other state actions, this PATCH carries a body: ShipShipment reads `trackingCode` from it. */
    public function testShipSendsTheTrackingCodeInTheMergePatchBody(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->shipment());
        $client->expects(self::once())
            ->method('patch')
            ->with(self::PATH . '/ship', ['trackingCode' => 'TRACK-9'], [])
            ->willReturn(['state' => 'shipped', 'tracking' => 'TRACK-9'] + $this->shipment());

        $result = $this->call(new ShipShipment($client, $this->audit()), '4', trackingCode: 'TRACK-9', confirm: true);

        self::assertTrue($result['shipped']);
        self::assertSame(['before' => null, 'after' => 'TRACK-9'], $result['diff']['tracking']);
    }

    public function testShipSendsNoBodyWhenThereIsNoTrackingCode(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->shipment());
        $client->expects(self::once())
            ->method('patch')
            ->with(self::PATH . '/ship', [], [])
            ->willReturn(['state' => 'shipped'] + $this->shipment());

        $result = $this->call(new ShipShipment($client, $this->audit()), '4', confirm: true);

        self::assertArrayNotHasKey('tracking', $result['diff']);
    }

    public function testShipTreatsABlankTrackingCodeAsNone(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->shipment());
        $client->expects(self::once())
            ->method('patch')
            ->with(self::PATH . '/ship', [], [])
            ->willReturn($this->shipment());

        $this->call(new ShipShipment($client, $this->audit()), '4', trackingCode: '   ', confirm: true);
    }

    /** Sylius does not answer 422 here: it asserts deep in the handler and returns 500. */
    public function testShipRefusesAShipmentThatIsNotReady(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['state' => 'cart'] + $this->shipment());
        $client->expects(self::never())->method('patch');

        $result = $this->call(new ShipShipment($client, $this->audit()), '4', confirm: true);

        self::assertSame('not_shippable', $result['reason']);
        self::assertSame('cart', $result['state']);
    }

    public function testShipReportsAnAlreadyShippedShipmentAsSuch(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['state' => 'shipped'] + $this->shipment());

        self::assertSame(
            'already_shipped',
            $this->call(new ShipShipment($client, $this->audit()), '4', confirm: true)['reason'],
        );
    }

    public function testShipRecordsAnAuditEntry(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->shipment());
        $client->method('patch')->willReturn($this->shipment());

        [$audit, $em] = $this->auditSpy();
        $em->expects(self::once())->method('persist')->with(self::callback(
            static fn (RecordedWrite $e): bool => 'ship_shipment' === $e->getTool() &&
                'shipment' === $e->getTargetType(),
        ));

        $this->call(new ShipShipment($client, $audit), '4', confirm: true);
    }

    public function testResendRefusesAShipmentThatHasNotShipped(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->shipment());
        $client->expects(self::never())->method('post');

        $result = $this->call(new ResendShipmentConfirmationEmail($client, $this->audit()), '4', confirm: true);

        self::assertSame('not_shipped', $result['reason']);
        self::assertSame('ready', $result['state']);
    }

    public function testResendPostsAnEmptyBodyToTheActionEndpoint(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn(['state' => 'shipped'] + $this->shipment());
        $client->expects(self::once())
            ->method('post')
            ->with(self::PATH . '/resend-confirmation-email', [], [])
            ->willReturn([]);

        $result = $this->call(new ResendShipmentConfirmationEmail($client, $this->audit()), '4', confirm: true);

        self::assertTrue($result['sent']);
        self::assertSame('TOKEN123', $result['orderTokenValue']);
    }

    public function testBothToolsRefuseABlankIdWithoutTouchingTheApi(): void
    {
        $client = $this->apiClient();
        $client->expects(self::never())->method('getDecoded');

        $audit = $this->audit();
        self::assertSame('not_found', $this->call(new ShipShipment($client, $audit), '', confirm: true)['reason']);
        self::assertSame(
            'not_found',
            $this->call(new ResendShipmentConfirmationEmail($client, $audit), '', confirm: true)['reason'],
        );
    }

    public function testTheWriteToolsRefuseWithoutElicitation(): void
    {
        $client = $this->apiClient();
        // Each tool on the state its own guard lets through: `ready` for ship, `shipped` for resend.
        $client->method('getDecoded')->willReturnOnConsecutiveCalls(
            $this->shipment(),
            ['state' => 'shipped'] + $this->shipment(),
        );
        $client->expects(self::never())->method('patch');
        $client->expects(self::never())->method('post');

        $context = $this->noElicitContext();
        $audit = $this->audit();

        self::assertSame(
            'human_confirmation_required',
            $this->call(new ShipShipment($client, $audit), '4', context: $context)['reason'],
        );
        self::assertSame(
            'human_confirmation_required',
            $this->call(new ResendShipmentConfirmationEmail($client, $audit), '4', context: $context)['reason'],
        );
    }

    public function testElicitationDeclinedShipsNothing(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->shipment());
        $client->expects(self::never())->method('patch');

        $tool = new ShipShipment($client, $this->audit());
        $context = $this->elicitingContext();

        $result = $this->runWithElicitation(
            static fn (): array => $tool('4', context: $context),
            ElicitAction::Decline,
        );

        self::assertFalse($result['shipped']);
    }

    /**
     * @return array<string, mixed>
     */
    private function shipment(): array
    {
        return [
            '@id' => '/api/v2/admin/shipments/4',
            'id' => 4,
            'state' => 'ready',
            'tracking' => null,
            'shippedAt' => null,
            'method' => '/api/v2/admin/shipping-methods/ups',
            'order' => '/api/v2/admin/orders/TOKEN123',
            'units' => [['id' => 1], ['id' => 2]],
        ];
    }
}
