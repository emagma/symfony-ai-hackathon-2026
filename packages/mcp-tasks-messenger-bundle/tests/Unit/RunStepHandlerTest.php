<?php

declare(strict_types=1);

namespace Emagma\McpTasksMessengerBundle\Tests\Unit;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Orchestration\StepExecutor;
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
use Emagma\McpTasksBundle\Testing\RecordingTaskNotifier;
use Emagma\McpTasksMessengerBundle\Handler\RunStepHandler;
use Emagma\McpTasksMessengerBundle\Message\RunStep;
use Emagma\McpTasksMessengerBundle\MessengerOrchestrator;
use Emagma\McpTasksMessengerBundle\Tests\RecordingBus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The steps of a task as messages: launched by the orchestrator, chained by the handler, the scripts played in
 * the real hardened process against tools in memory.
 */
#[CoversClass(MessengerOrchestrator::class)]
#[CoversClass(RunStepHandler::class)]
final class RunStepHandlerTest extends TestCase
{
    private string $directory;

    private InMemoryTaskStore $store;

    private RecordingBus $bus;

    private FakeTools $tools;

    private RecordingTaskNotifier $notifier;

    private TaskManager $manager;

    private RunStepHandler $handler;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mcp-tasks-messenger-' . bin2hex(random_bytes(6));
        $this->store = new InMemoryTaskStore();
        $this->bus = new RecordingBus();
        $this->notifier = new RecordingTaskNotifier();
        $this->tools = new FakeTools([
            'upsert_taxons' => static fn (): array => ['ok' => true, 'results' => []],
            'search_products' => static fn (): array => ['items' => [], 'hasMore' => false],
            'delete_taxons' => static fn (): array => ['ok' => false, 'reason' => 'conflict'],
        ]);
        $delegation = new FakeDelegation();
        $clock = new MockClock('2026-11-21 10:00:00');
        $files = new TaskFiles($this->directory);
        $this->manager = new TaskManager($this->store, $files, new ScriptInspector(), $this->tools, new FakeScriptReader(), $delegation, new MessengerOrchestrator($this->bus), $clock);
        $this->handler = new RunStepHandler(
            new StepExecutor($this->store, new StepRunner(new ProcessSandbox(), $this->tools, $this->store, $files, $clock), $delegation, $this->notifier, $clock),
            new TaskLifecycle($this->store, $delegation, $this->notifier, $clock),
            $this->store,
            $this->bus,
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testTheStepsArePlayedInOrderOneMessageEach(): void
    {
        $task = $this->started(
            "<?php\ncall('upsert_taxons', ['items' => []]);",
            "<?php\ncall('search_products', []);",
        );

        $first = $this->bus->take();
        self::assertEquals([new RunStep($task->getId(), 0)], $first);
        ($this->handler)($first[0]);
        self::assertSame(TaskStatus::Running, $task->getStatus());
        self::assertSame(['upsert_taxons'], $this->tools->called());

        $second = $this->bus->take();
        self::assertEquals([new RunStep($task->getId(), 1)], $second);
        ($this->handler)($second[0]);

        self::assertSame(TaskStatus::Done, $task->getStatus());
        self::assertSame(['upsert_taxons', 'search_products'], $this->tools->called());
        self::assertSame([], $this->bus->take());
        self::assertSame([$task->getId()], $this->notifier->finished);
    }

    public function testAMessageDeliveredTwicePlaysItsStepOnce(): void
    {
        $task = $this->started("<?php\ncall('search_products', []);");
        $message = $this->bus->take()[0];
        self::assertInstanceOf(RunStep::class, $message);

        ($this->handler)($message);
        ($this->handler)($message);

        self::assertSame(TaskStatus::Done, $task->getStatus());
        self::assertSame(['search_products'], $this->tools->called());
        self::assertSame([$task->getId()], $this->notifier->finished);
    }

    public function testAFailedStepSendsNothingMore(): void
    {
        $task = $this->started("<?php\ncall('delete_taxons', ['codes' => ['x']]);", "<?php\ncall('search_products', []);");
        $this->work();

        self::assertSame(TaskStatus::Suspended, $task->getStatus());
        self::assertNotContains('search_products', $this->tools->called());
        self::assertSame([$task->getId()], $this->notifier->suspended);
    }

    public function testACancelledTaskPlaysNothingMore(): void
    {
        $task = $this->started("<?php\ncall('search_products', []);", "<?php\ncall('upsert_taxons', []);");
        $first = $this->bus->take()[0];
        self::assertInstanceOf(RunStep::class, $first);
        ($this->handler)($first);
        self::assertTrue($this->manager->cancel($task));
        $this->work();

        self::assertSame(TaskStatus::Cancelled, $task->getStatus());
        self::assertSame(['search_products'], $this->tools->called());
    }

    private function started(string ...$scripts): Task
    {
        $steps = [];
        foreach (array_values($scripts) as $index => $script) {
            $steps[] = ['name' => 'step-' . $index, 'script' => $script];
        }
        $task = $this->manager->create('alice', 'Reorganise', 'recap', "<?php\nconst PAGE = 1;\n", $steps);
        self::assertNull($this->manager->start($task));

        return $task;
    }

    /**
     * Consumes the queue as the worker does, one message at a time.
     */
    private function work(): void
    {
        while ([] !== $messages = $this->bus->take()) {
            foreach ($messages as $message) {
                self::assertInstanceOf(RunStep::class, $message);
                ($this->handler)($message);
            }
        }
    }
}
