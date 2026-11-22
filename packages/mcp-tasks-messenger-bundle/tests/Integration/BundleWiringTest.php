<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle\Tests\Integration;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksMessengerBundle\EmagmaMcpTasksMessengerBundle;
use Emagma\McpTasksMessengerBundle\EventListener\InterruptedTasksListener;
use Emagma\McpTasksMessengerBundle\Handler\RunStepHandler;
use Emagma\McpTasksMessengerBundle\Message\RunStep;
use Emagma\McpTasksMessengerBundle\MessengerOrchestrator;
use Emagma\McpTasksMessengerBundle\Tests\Integration\Fixture\TestKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/** The bundle booted beside the tasks bundle: the container compiles, the orchestrator is the default one, a step goes to its own queue. */
#[CoversClass(EmagmaMcpTasksMessengerBundle::class)]
#[CoversClass(MessengerOrchestrator::class)]
#[CoversClass(RunStep::class)]
final class BundleWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testItIsTheOrchestratorOfTheTasksWithoutAnyConfiguration(): void
    {
        self::bootKernel();

        self::assertInstanceOf(MessengerOrchestrator::class, self::getContainer()->get('test.orchestrator'));
    }

    public function testItsHandlerIsWired(): void
    {
        self::bootKernel();

        self::assertInstanceOf(RunStepHandler::class, self::getContainer()->get('test.handler'));
    }

    public function testTheListenerIsSubscribedToTheStartOfAWorker(): void
    {
        self::bootKernel();

        $dispatcher = self::getContainer()->get('test.event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $listeners = $dispatcher->getListeners(WorkerStartedEvent::class);

        self::assertNotSame([], array_filter($listeners, static fn (mixed $listener): bool => \is_array($listener) && $listener[0] instanceof InterruptedTasksListener));
    }

    public function testTheFirstStepOfATaskIsSentToTheQueueOfItsOwn(): void
    {
        self::bootKernel();
        $orchestrator = self::getContainer()->get('test.orchestrator');
        self::assertInstanceOf(MessengerOrchestrator::class, $orchestrator);

        $orchestrator->start($this->task('task-1'));

        $transport = self::getContainer()->get('messenger.transport.mcp_tasks');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(RunStep::class, $message);
        self::assertSame('task-1', $message->taskId);
        self::assertSame(0, $message->step);
    }

    private function task(string $id): Task
    {
        return new Task($id, 'alice', 'objective', 'recap', 'reading', [], ['first'], new \DateTimeImmutable());
    }
}
