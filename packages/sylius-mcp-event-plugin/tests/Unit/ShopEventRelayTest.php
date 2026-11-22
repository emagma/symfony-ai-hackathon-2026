<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpEventPlugin\Tests\Unit;

use Emagma\SyliusMcpEventPlugin\Relay\ShopEventRelay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Webhook\Messenger\SendWebhookMessage;

#[CoversClass(ShopEventRelay::class)]
final class ShopEventRelayTest extends TestCase
{
    public function testAnEventIsSentAsASignedWebhookThroughMessenger(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(self::callback(static function (object $message): bool {
            self::assertInstanceOf(SendWebhookMessage::class, $message);
            self::assertSame('http://mcp-server/webhook/sylius', $message->getSubscriber()->getUrl());
            self::assertSame('s3cret', $message->getSubscriber()->getSecret());
            self::assertSame('customer.created', $message->getEvent()->getName());
            self::assertSame(['id' => '42'], $message->getEvent()->getPayload());
            self::assertNotSame('', $message->getEvent()->getId());

            return true;
        }))->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        (new ShopEventRelay($bus, 'http://mcp-server/webhook/sylius', 's3cret'))->relay('customer.created', ['id' => '42']);
    }

    #[DataProvider('unconfigured')]
    public function testWithoutAUrlOrASecretNothingIsSent(?string $url, ?string $secret): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $relay = new ShopEventRelay($bus, $url, $secret);

        $relay->relay('customer.created', ['id' => '42']);

        self::assertFalse($relay->isConfigured());
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function unconfigured(): iterable
    {
        yield 'nothing' => [null, null];
        yield 'no secret' => ['http://mcp-server/webhook/sylius', ''];
        yield 'no url' => ['', 's3cret'];
    }
}
