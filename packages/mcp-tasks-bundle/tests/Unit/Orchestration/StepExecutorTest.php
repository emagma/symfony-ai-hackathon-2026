<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Orchestration;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Orchestration\StepExecutor;
use Emagma\McpTasksBundle\Orchestration\StepOutcome;
use Emagma\McpTasksBundle\Orchestration\TaskLifecycle;
use Emagma\McpTasksBundle\Runner\StepRunner;
use Emagma\McpTasksBundle\Sandbox\ProcessSandbox;
use Emagma\McpTasksBundle\Script\ScriptInspector;
use Emagma\McpTasksBundle\Script\TaskFiles;
use Emagma\McpTasksBundle\TaskManager;
use Emagma\McpTasksBundle\TaskStatus;
use Emagma\McpTasksBundle\Testing\FakeDelegation;
use Emagma\McpTasksBundle\Testing\FakeScriptReader;
use Emagma\McpTasksBundle\Testing\FakeTools;
use Emagma\McpTasksBundle\Testing\InMemoryTaskStore;
use Emagma\McpTasksBundle\Testing\RecordingOrchestrator;
use Emagma\McpTasksBundle\Testing\RecordingTaskNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

/**
 * What an orchestrator may rely on, whoever it is: a step is played once, in order, and the task ends or stops
 * only through the bundle.
 */
#[CoversClass(StepExecutor::class)]
#[CoversClass(TaskLifecycle::class)]
final class StepExecutorTest extends TestCase
{
    private string $directory;

    private InMemoryTaskStore $store;

    private FakeDelegation $delegation;

    private RecordingTaskNotifier $notifier;

    private TaskManager $manager;

    private StepExecutor $executor;

    private TaskLifecycle $lifecycle;

    /** @var list<bool> what isStepInFlight() answered during each call */
    private array $inFlight = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mcp-tasks-' . bin2hex(random_bytes(6));
        $this->store = new InMemoryTaskStore();
        $this->delegation = new FakeDelegation();
        $this->notifier = new RecordingTaskNotifier();
        $clock = new MockClock('2026-11-21 10:00:00');
        $files = new TaskFiles($this->directory);
        $tools = new FakeTools([
            'search_products' => function (array $arguments): array {
                $this->inFlight[] = $this->lifecycle->isStepInFlight((string) array_key_first($this->store->tasks));

                return ['items' => [], 'hasMore' => false];
            },
        ]);
        $this->manager = new TaskManager($this->store, $files, new ScriptInspector(), $tools, new FakeScriptReader(), $this->delegation, new RecordingOrchestrator(), $clock);
        $this->executor = new StepExecutor($this->store, new StepRunner(new ProcessSandbox(), $tools, $this->store, $files, $clock), $this->delegation, $this->notifier, $clock);
        $this->lifecycle = new TaskLifecycle($this->store, $this->delegation, $this->notifier, $clock);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testAStepIsInFlightFromItsClaimToItsEnd(): void
    {
        $task = $this->started(2);

        self::assertFalse($this->lifecycle->isStepInFlight($task->getId()));
        self::assertSame(StepOutcome::Done, $this->executor->execute($task->getId(), 0));

        self::assertSame([true], $this->inFlight);
        self::assertFalse($this->lifecycle->isStepInFlight($task->getId()));
        self::assertSame(0, $task->getClaimedStep());
        self::assertNotNull($task->getStartedAt());
    }

    public function testAStepThatDoesNotExistIsSkippedWithoutHarm(): void
    {
        $task = $this->started(1);

        self::assertSame(StepOutcome::Skipped, $this->executor->execute($task->getId(), 7));
        self::assertSame(StepOutcome::Skipped, $this->executor->execute($task->getId(), -1));
        self::assertSame(StepOutcome::Skipped, $this->executor->execute('unknown', 0));

        self::assertSame(TaskStatus::Queued, $task->getStatus());
        self::assertSame(StepOutcome::Done, $this->executor->execute($task->getId(), 0));
    }

    public function testATaskCancelledBeforeItsFirstStepPlaysNothing(): void
    {
        $task = $this->started(1);
        $this->manager->cancel($task);

        self::assertSame(StepOutcome::Skipped, $this->executor->execute($task->getId(), 0));
        self::assertSame(TaskStatus::Cancelled, $task->getStatus());
        self::assertSame([], $this->inFlight);
    }

    public function testATaskEndsOnlyOnceItsLastStepIsDone(): void
    {
        $task = $this->started(2);
        $this->executor->execute($task->getId(), 0);

        $this->lifecycle->finished($task->getId());
        self::assertSame(TaskStatus::Running, $task->getStatus());

        $task->setStepInFlight(true);
        $task->setClaimedStep(1);
        $this->lifecycle->finished($task->getId());
        self::assertSame(TaskStatus::Running, $task->getStatus());

        $task->setStepInFlight(false);
        $this->lifecycle->finished($task->getId());
        self::assertSame(TaskStatus::Done, $task->getStatus());
        self::assertSame([$task->getId()], $this->notifier->finished);
    }

    public function testAnInterruptedTaskIsSuspendedAndItsOwnerTold(): void
    {
        $task = $this->started(2);
        $this->executor->execute($task->getId(), 0);
        $task->setStepInFlight(true);

        $this->lifecycle->interrupted($task->getId());

        self::assertSame(TaskStatus::Suspended, $task->getStatus());
        self::assertSame(TaskLifecycle::INTERRUPTED, $task->getError());
        self::assertFalse($task->isStepInFlight());
        self::assertNotNull($task->getFinishedAt());
        self::assertSame([$task->getId()], $this->notifier->suspended);
        // Never played again.
        self::assertSame(StepOutcome::Skipped, $this->executor->execute($task->getId(), 1));
        $this->lifecycle->interrupted($task->getId());
        self::assertSame([$task->getId()], $this->notifier->suspended);
    }

    public function testATaskEndsEvenWhenItsOwnerCannotBeTold(): void
    {
        $task = $this->started(1);
        $this->executor->execute($task->getId(), 0);
        $this->delegation->revoked = true;

        $this->lifecycle->finished($task->getId());

        // Over all the same: telling is a courtesy, without a token there is no address to tell.
        self::assertSame(TaskStatus::Done, $task->getStatus());
        self::assertSame([], $this->notifier->finished);
    }

    private function started(int $steps): Task
    {
        $task = $this->manager->create('alice', 'Read', 'recap', "<?php\nconst PAGE = 1;\n", array_map(
            static fn (int $index): array => ['name' => 'read-' . $index, 'script' => "<?php\ncall('search_products', ['page' => PAGE]);"],
            range(1, $steps),
        ));
        self::assertNull($this->manager->start($task));

        return $task;
    }
}
