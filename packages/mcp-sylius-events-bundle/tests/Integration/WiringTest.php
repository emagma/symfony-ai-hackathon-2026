<?php

declare(strict_types=1);

namespace Emagma\McpSyliusEventsBundle\Tests\Integration;

use Emagma\McpSyliusEventsBundle\EmagmaMcpSyliusEventsBundle;
use Emagma\McpSyliusEventsBundle\Entity\ShopEvent;
use Emagma\McpSyliusEventsBundle\Event\ShopEventRecorded;
use Emagma\McpSyliusEventsBundle\Store\DoctrineShopEventStore;
use Emagma\McpSyliusEventsBundle\Store\ShopEventStoreInterface;
use Emagma\McpSyliusEventsBundle\Tests\Integration\Fixture\TestKernel;
use Emagma\McpSyliusEventsBundle\Webhook\ShopEventConsumer;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Server\HeadersConfigurator;
use Symfony\Component\Webhook\Server\HeaderSignatureConfigurator;
use Symfony\Component\Webhook\Server\JsonBodyConfigurator;
use Symfony\Component\Webhook\Server\NativeJsonPayloadSerializer;

/** The bundle as the container of a host builds it: the signed route, the consumer, the store, the journal. */
#[CoversClass(EmagmaMcpSyliusEventsBundle::class)]
#[CoversClass(ShopEventConsumer::class)]
#[CoversClass(DoctrineShopEventStore::class)]
#[CoversClass(ShopEvent::class)]
#[CoversClass(ShopEventRecorded::class)]
final class WiringTest extends DatabaseTestCase
{
    public function testTheStoreIsInjectedByItsInterface(): void
    {
        self::assertInstanceOf(DoctrineShopEventStore::class, $this->service(ShopEventStoreInterface::class));
    }

    public function testASignedWebhookIsRecordedOnceAndDispatchedOnce(): void
    {
        $dispatched = [];
        $this->service(EventDispatcherInterface::class)->addListener(ShopEventRecorded::class, static function (ShopEventRecorded $event) use (&$dispatched): void {
            $dispatched[] = $event;
        });

        self::assertSame(202, $this->deliver('customer.created', 'evt-1', ['id' => '42'], TestKernel::SECRET)->getStatusCode());
        self::assertSame(202, $this->deliver('customer.created', 'evt-1', ['id' => '42'], TestKernel::SECRET)->getStatusCode(), 'delivered again');

        $events = $this->service(ShopEventStoreInterface::class)->after('customer.created', 0);
        self::assertCount(1, $events);
        self::assertSame('evt-1', $events[0]->getEventId());
        self::assertSame('42', $events[0]->getSubjectId());
        self::assertCount(1, $dispatched);
        self::assertSame($events[0]->getSequence(), $dispatched[0]->sequence);
    }

    public function testAWebhookSignedWithAnotherSecretIsRefusedAndRecordsNothing(): void
    {
        $response = $this->deliver('customer.created', 'evt-2', ['id' => '43'], 'another-secret');

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertSame([], $this->service(ShopEventStoreInterface::class)->after('customer.created', 0));
    }

    /**
     * Signed as the shop's Symfony Webhook transport signs it.
     *
     * @param array<string, mixed> $payload
     */
    private function deliver(string $name, string $id, array $payload, string $secret): Response
    {
        $event = new RemoteEvent($name, $id, $payload);
        $options = new HttpOptions();
        (new HeadersConfigurator())->configure($event, $secret, $options);
        (new JsonBodyConfigurator(new NativeJsonPayloadSerializer()))->configure($event, $secret, $options);
        (new HeaderSignatureConfigurator())->configure($event, $secret, $options);
        $request = $options->toArray();
        \assert(\is_array($request['headers']) && \is_string($request['body']));

        $server = [];
        foreach ($request['headers'] as $header => $value) {
            \assert(\is_string($header) && \is_string($value));
            $server['Content-Type' === $header ? 'CONTENT_TYPE' : 'HTTP_' . strtoupper(str_replace('-', '_', $header))] = $value;
        }

        return self::$kernel->handle(Request::create('/webhook/' . ShopEventConsumer::WEBHOOK, 'POST', server: $server, content: $request['body']));
    }
}
