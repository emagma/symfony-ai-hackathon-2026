<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Functional\Agents;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Emagma\McpAgentsBundle\Alert\AgentAlertNotification;
use Emagma\McpAgentsBundle\Schedule\RunAgent;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\RemoteEvent\RemoteEvent;
use Symfony\Component\Webhook\Server\HeadersConfigurator;
use Symfony\Component\Webhook\Server\HeaderSignatureConfigurator;
use Symfony\Component\Webhook\Server\JsonBodyConfigurator;
use Symfony\Component\Webhook\Server\NativeJsonPayloadSerializer;

/**
 * The shop's webhook wakes the fraud agent through the whole chain of the server: Symfony Webhook, the journal,
 * ShopEventRecorded, the hackathon bundle's listener, AgentRunner, the deduplication middleware on the DBAL lock
 * store, the transport mcp_agents (in memory in this environment, serialized as a queue would).
 */
final class AgentsOnEventsTest extends WebTestCase
{
    private KernelBrowser $client;

    /**
     * What each request put in mcp_agents: between two requests the kernel resets its services, the transports in
     * memory among them (not the locks, in the database).
     *
     * @var list<object>
     */
    private array $queued = [];

    /** @var array{server: mixed, env: mixed} what `.env.test` had set, put back on tearDown */
    private array $previousDatabaseUrl;

    protected function setUp(): void
    {
        $this->previousDatabaseUrl = ['server' => $_SERVER['DATABASE_URL'] ?? null, 'env' => $_ENV['DATABASE_URL'] ?? null];
        $_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->client = self::createClient();
        // One kernel for the whole test: the database in memory, the queue and the locks survive the requests.
        $this->client->disableReboot();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        // The schema listeners add the tables of the lock store and of the schedule's cache pool.
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    protected function tearDown(): void
    {
        foreach (['server' => &$_SERVER, 'env' => &$_ENV] as $key => &$bag) {
            if (null === $this->previousDatabaseUrl[$key]) {
                unset($bag['DATABASE_URL']);
            } else {
                $bag['DATABASE_URL'] = $this->previousDatabaseUrl[$key];
            }
        }
        unset($bag);
        parent::tearDown();
    }

    public function testANewAccountLeavesOneRunAgentInTheQueueAndTwoStillLeaveOne(): void
    {
        $this->webhook(new RemoteEvent('customer.created', 'evt-1', ['id' => '41']));

        self::assertEquals([new RunAgent('fraud')], $this->queued);

        $this->webhook(new RemoteEvent('customer.created', 'evt-2', ['id' => '42']));
        $this->webhook(new RemoteEvent('order.placed', 'evt-3', ['id' => '9']));

        self::assertEquals([new RunAgent('fraud')], $this->queued, 'one pass is waiting: it will read both accounts');
    }

    public function testOnceTheWorkerReceivedItANewAccountAsksForAnotherPass(): void
    {
        $this->webhook(new RemoteEvent('customer.created', 'evt-1', ['id' => '41']));

        // What the deduplication middleware does when the worker receives the message, with the key as the queue
        // gave it back: its token went through the serialization, so the release frees the key.
        [$envelope] = $this->transport('mcp_agents')->get();
        $stamp = $envelope->last(DeduplicateStamp::class);
        self::assertInstanceOf(DeduplicateStamp::class, $stamp);
        $locks = self::getContainer()->get('lock.factory');
        \assert($locks instanceof LockFactory);
        $locks->createLockFromKey($stamp->getKey())->release();

        $this->webhook(new RemoteEvent('customer.created', 'evt-2', ['id' => '42']));

        self::assertEquals([new RunAgent('fraud'), new RunAgent('fraud')], $this->queued);
    }

    public function testAWebhookDeliveredAgainWakesNoOne(): void
    {
        $this->webhook(new RemoteEvent('order.placed', 'evt-1', ['id' => '9']));
        $this->webhook(new RemoteEvent('order.placed', 'evt-1', ['id' => '9']));

        self::assertSame([], $this->queued);
    }

    public function testAnAlertEmailIsQueuedInAsyncNotSent(): void
    {
        $notifier = self::getContainer()->get('notifier');
        \assert($notifier instanceof NotifierInterface);

        // What AgentAlerter does: Symfony Notifier, the email channel of the importance.
        $notifier->send((new AgentAlertNotification('[fraud · high] customer #42', 'mcp-agents@localhost'))->content('An alert.'), new Recipient('ops@example.com'));

        $messages = $this->messages('async');
        self::assertCount(1, $messages);
        self::assertInstanceOf(SendEmailMessage::class, $messages[0]);
    }

    private function webhook(RemoteEvent $event): void
    {
        // The secret of the stack wins over .env.test when the suite runs in the container.
        $secret = $_SERVER['MCP_EVENT_WEBHOOK_SECRET'] ?? $_ENV['MCP_EVENT_WEBHOOK_SECRET'];
        \assert(\is_string($secret));

        // Signed as the shop's Symfony Webhook transport signs it.
        $options = new HttpOptions();
        (new HeadersConfigurator())->configure($event, $secret, $options);
        (new JsonBodyConfigurator(new NativeJsonPayloadSerializer()))->configure($event, $secret, $options);
        (new HeaderSignatureConfigurator())->configure($event, $secret, $options);
        $request = $options->toArray();
        \assert(\is_array($request['headers']) && \is_string($request['body']));

        $server = [];
        foreach ($request['headers'] as $name => $value) {
            \assert(\is_string($name) && \is_string($value));
            $server['Content-Type' === $name ? 'CONTENT_TYPE' : 'HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $this->client->request('POST', '/webhook/sylius', server: $server, content: $request['body']);

        self::assertResponseStatusCodeSame(202);
        array_push($this->queued, ...$this->messages('mcp_agents'));
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.' . $name);
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }

    /**
     * @return list<object> the messages sent to a transport
     */
    private function messages(string $name): array
    {
        return array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), $this->transport($name)->getSent());
    }
}
