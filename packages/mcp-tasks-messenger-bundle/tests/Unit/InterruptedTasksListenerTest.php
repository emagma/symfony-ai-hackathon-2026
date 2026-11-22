<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle\Tests\Unit;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Orchestration\TaskLifecycle;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Emagma\McpTasksBundle\TaskStatus;
use Emagma\McpTasksBundle\Testing\FakeDelegation;
use Emagma\McpTasksBundle\Testing\InMemoryTaskStore;
use Emagma\McpTasksBundle\Testing\RecordingTaskNotifier;
use Emagma\McpTasksMessengerBundle\EventListener\InterruptedTasksListener;
use Emagma\McpTasksMessengerBundle\Tests\RecordingBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;
use Symfony\Component\Messenger\Worker;

#[CoversClass(InterruptedTasksListener::class)]
final class InterruptedTasksListenerTest extends TestCase
{
    private InMemoryTaskStore $store;

    private RecordingTaskNotifier $notifier;

    private InterruptedTasksListener $listener;

    protected function setUp(): void
    {
        $this->store = new InMemoryTaskStore();
        $this->notifier = new RecordingTaskNotifier();
        $this->listener = new InterruptedTasksListener(
            $this->store,
            new TaskLifecycle($this->store, new FakeDelegation(), $this->notifier, new MockClock('2026-11-21 10:00:00')),
        );
    }

    public function testATaskCutByAStopOfTheWorkerDuringItsStepIsSuspendedNeverPlayedAgain(): void
    {
        $cut = $this->task('cut', TaskStatus::Running, inFlight: true);
        $queued = $this->task('queued', TaskStatus::Queued);

        // Another consumer of the same application starts: the tasks are not its business.
        ($this->listener)($this->workerOf('scheduler_mcp_agents'));
        self::assertSame(TaskStatus::Running, $cut->getStatus());

        ($this->listener)($this->workerOf('mcp_tasks'));
        self::assertSame(TaskStatus::Suspended, $cut->getStatus());
        self::assertStringStartsWith('interrupted:', (string) $cut->getError());
        self::assertNotNull($cut->getFinishedAt());
        self::assertFalse($cut->isStepInFlight());
        self::assertSame(['cut'], $this->notifier->suspended);
        self::assertSame(TaskStatus::Queued, $queued->getStatus());
    }

    public function testATaskBetweenTwoStepsLostNothing(): void
    {
        // Its step 1 is done, the message of step 2 waits in the queue: the worker stopped after the hour.
        $between = $this->task('between', TaskStatus::Running);
        $between->setClaimedStep(0);

        ($this->listener)($this->workerOf('mcp_tasks'));

        self::assertSame(TaskStatus::Running, $between->getStatus());
        self::assertNull($between->getError());
        self::assertSame([], $this->notifier->suspended);
    }

    public function testAWorkerStartsOnADatabaseNotMigratedYet(): void
    {
        // A first start: the consumers come up before the migrations, there is no table of the tasks.
        $store = $this->createStub(TaskStoreInterface::class);
        $store->method('findByStatus')->willThrowException(new TableNotFoundException(new class('Table \'mcp.mcp_task\' doesn\'t exist') extends AbstractException {
        }, null));
        $listener = new InterruptedTasksListener($store, new TaskLifecycle($store, new FakeDelegation(), $this->notifier, new MockClock()));

        $listener($this->workerOf('mcp_tasks'));

        self::assertSame([], $this->notifier->suspended);
    }

    public function testAnyOtherFailureOfTheStoreStopsTheWorker(): void
    {
        $store = $this->createStub(TaskStoreInterface::class);
        $store->method('findByStatus')->willThrowException(new \RuntimeException('Connection refused'));
        $listener = new InterruptedTasksListener($store, new TaskLifecycle($store, new FakeDelegation(), $this->notifier, new MockClock()));

        $this->expectExceptionMessage('Connection refused');
        $listener($this->workerOf('mcp_tasks'));
    }

    private function task(string $id, TaskStatus $status, bool $inFlight = false): Task
    {
        $task = new Task($id, 'alice', 'objective', 'recap', 'reading', [], ['first', 'second'], new \DateTimeImmutable());
        $task->setStatus($status);
        $task->setStepInFlight($inFlight);
        $this->store->create($task);

        return $task;
    }

    private function workerOf(string $transport): WorkerStartedEvent
    {
        return new WorkerStartedEvent(new Worker([$transport => $this->createStub(ReceiverInterface::class)], new RecordingBus()));
    }
}
