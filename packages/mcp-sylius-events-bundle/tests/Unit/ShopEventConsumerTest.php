<?php

declare(strict_types=1);

namespace Emagma\McpSyliusEventsBundle\Tests\Unit;

use Emagma\McpSyliusEventsBundle\Event\ShopEventRecorded;
use Emagma\McpSyliusEventsBundle\Testing\InMemoryShopEventStore;
use Emagma\McpSyliusEventsBundle\Webhook\ShopEventConsumer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\RemoteEvent\RemoteEvent;

#[CoversClass(ShopEventConsumer::class)]
#[CoversClass(ShopEventRecorded::class)]
#[CoversClass(InMemoryShopEventStore::class)]
final class ShopEventConsumerTest extends TestCase
{
    /** @var list<ShopEventRecorded> */
    private array $dispatched = [];

    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatched = [];
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(ShopEventRecorded::class, function (ShopEventRecorded $event): void {
            $this->dispatched[] = $event;
        });
    }

    public function testAnEventIsWrittenToTheJournalWithItsSubject(): void
    {
        $store = new InMemoryShopEventStore();
        $consumer = new ShopEventConsumer($store, new MockClock('2026-11-14 10:00:00'), $this->dispatcher);

        $consumer->consume(new RemoteEvent('customer.created', 'evt-1', ['id' => '42', 'createdAt' => '2026-11-14T09:59:58+00:00']));

        [$event] = $store->after('customer.created', 0);
        self::assertSame('evt-1', $event->getEventId());
        self::assertSame('42', $event->getSubjectId());
        self::assertSame(1, $event->getSequence());
        self::assertSame('2026-11-14 10:00:00', $event->getReceivedAt()->format('Y-m-d H:i:s'));
    }

    public function testAWebhookDeliveredTwiceIsRecordedAndDispatchedOnce(): void
    {
        $store = new InMemoryShopEventStore();
        $consumer = new ShopEventConsumer($store, new MockClock(), $this->dispatcher);

        $consumer->consume(new RemoteEvent('customer.created', 'evt-1', ['id' => '42']));
        $consumer->consume(new RemoteEvent('customer.created', 'evt-1', ['id' => '42']));

        self::assertCount(1, $store->after('customer.created', 0));
        self::assertCount(1, $this->dispatched, 'a webhook delivered again wakes no one');
    }

    public function testARecordedEventIsDispatchedWithItsNameSubjectAndSequence(): void
    {
        $consumer = new ShopEventConsumer(new InMemoryShopEventStore(), new MockClock(), $this->dispatcher);

        $consumer->consume(new RemoteEvent('order.placed', 'evt-1', ['id' => 9]));
        $consumer->consume(new RemoteEvent('customer.created', 'evt-2', ['id' => '42']));

        self::assertEquals([
            new ShopEventRecorded('order.placed', '9', 1),
            new ShopEventRecorded('customer.created', '42', 2),
        ], $this->dispatched);
    }

    public function testAReaderResumesAfterTheLastSequenceItSawForItsOwnEventName(): void
    {
        $store = new InMemoryShopEventStore();
        $consumer = new ShopEventConsumer($store, new MockClock(), $this->dispatcher);
        $consumer->consume(new RemoteEvent('customer.created', 'evt-1', ['id' => '1']));
        $consumer->consume(new RemoteEvent('order.placed', 'evt-2', ['id' => '9']));
        $consumer->consume(new RemoteEvent('customer.created', 'evt-3', ['id' => '2']));

        $next = $store->after('customer.created', 1);

        self::assertSame(['evt-3'], array_map(static fn ($event): string => $event->getEventId(), $next));
    }
}
