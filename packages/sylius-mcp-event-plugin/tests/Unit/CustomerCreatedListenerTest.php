<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpEventPlugin\Tests\Unit;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Emagma\SyliusMcpEventPlugin\EventListener\CustomerCreatedListener;
use Emagma\SyliusMcpEventPlugin\Relay\ShopEventRelay;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Customer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Webhook\Messenger\SendWebhookMessage;

#[CoversClass(CustomerCreatedListener::class)]
final class CustomerCreatedListenerTest extends TestCase
{
    public function testANewCustomerIsRelayedByItsIdOnlyNeverItsEmail(): void
    {
        $customer = new Customer();
        $customer->setEmail('jean@example.com');
        $customer->setCreatedAt(new \DateTimeImmutable('2026-11-14T10:00:00+00:00'));
        (new \ReflectionProperty($customer, 'id'))->setValue($customer, 42);

        $sent = [];
        $this->listener($sent)->postPersist(new LifecycleEventArgs($customer, $this->createStub(ObjectManager::class)));

        self::assertCount(1, $sent);
        self::assertSame('customer.created', $sent[0]->getEvent()->getName());
        self::assertSame(['id' => '42', 'createdAt' => '2026-11-14T10:00:00+00:00'], $sent[0]->getEvent()->getPayload());
    }

    public function testAnythingElseBeingPersistedIsIgnored(): void
    {
        $sent = [];
        $this->listener($sent)->postPersist(new LifecycleEventArgs(new \stdClass(), $this->createStub(ObjectManager::class)));

        self::assertSame([], $sent);
    }

    /**
     * @param list<SendWebhookMessage> $sent
     */
    private function listener(array &$sent): CustomerCreatedListener
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$sent): Envelope {
            self::assertInstanceOf(SendWebhookMessage::class, $message);
            $sent[] = $message;

            return new Envelope($message);
        });

        return new CustomerCreatedListener(new ShopEventRelay($bus, 'http://mcp-server/webhook/sylius', 's3cret'));
    }
}
