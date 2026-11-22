<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Command;

use Emagma\McpTasksBundle\Command\FinishTaskCommand;
use Emagma\McpTasksBundle\Command\InterruptTaskCommand;
use Emagma\McpTasksBundle\Command\PlayStepCommand;
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
use Emagma\McpTasksBundle\Testing\RecordingOrchestrator;
use Emagma\McpTasksBundle\Testing\RecordingTaskNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * An external orchestrator drives a task through the console: one exit code per outcome.
 */
#[CoversClass(PlayStepCommand::class)]
#[CoversClass(FinishTaskCommand::class)]
#[CoversClass(InterruptTaskCommand::class)]
final class OrchestratorCommandsTest extends TestCase
{
    private string $directory;

    private InMemoryTaskStore $store;

    private FakeTools $tools;

    private TaskManager $manager;

    private CommandTester $step;

    private CommandTester $finish;

    private CommandTester $interrupted;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mcp-tasks-' . bin2hex(random_bytes(6));
        $this->store = new InMemoryTaskStore();
        $delegation = new FakeDelegation();
        $notifier = new RecordingTaskNotifier();
        $clock = new MockClock('2026-11-21 10:00:00');
        $files = new TaskFiles($this->directory);
        $this->tools = new FakeTools([
            'search_products' => static fn (): array => ['items' => [], 'hasMore' => false],
            'delete_taxons' => static fn (): array => ['ok' => false, 'reason' => 'conflict'],
        ]);
        $this->manager = new TaskManager($this->store, $files, new ScriptInspector(), $this->tools, new FakeScriptReader(), $delegation, new RecordingOrchestrator(), $clock);
        $lifecycle = new TaskLifecycle($this->store, $delegation, $notifier, $clock);
        $this->step = new CommandTester(new PlayStepCommand(new StepExecutor($this->store, new StepRunner(new ProcessSandbox(), $this->tools, $this->store, $files, $clock), $delegation, $notifier, $clock)));
        $this->finish = new CommandTester(new FinishTaskCommand($lifecycle, $this->store));
        $this->interrupted = new CommandTester(new InterruptTaskCommand($lifecycle, $this->store));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testATaskPlayedFromTheConsole(): void
    {
        $id = $this->started("<?php\ncall('search_products', []);");

        self::assertSame(1, $this->finish->execute(['taskId' => $id]), 'not started: nothing to end');
        self::assertSame(0, $this->step->execute(['taskId' => $id, 'step' => '0']));
        self::assertSame(PlayStepCommand::SKIPPED, $this->step->execute(['taskId' => $id, 'step' => '0']), 'a step played again');
        self::assertSame(0, $this->finish->execute(['taskId' => $id]));
        self::assertSame(TaskStatus::Done, $this->store->status($id));
        self::assertSame(1, $this->interrupted->execute(['taskId' => $id]), 'a task over is not interrupted');
    }

    public function testAFailedStepSuspendsTheTask(): void
    {
        $id = $this->started("<?php\ncall('delete_taxons', ['codes' => ['x']]);");

        self::assertSame(1, $this->step->execute(['taskId' => $id, 'step' => '0']));
        self::assertSame(TaskStatus::Suspended, $this->store->status($id));
    }

    public function testALostStepSuspendsTheTask(): void
    {
        $id = $this->started("<?php\ncall('search_products', []);");
        $this->step->execute(['taskId' => $id, 'step' => '0']);

        self::assertSame(0, $this->interrupted->execute(['taskId' => $id]));
        self::assertSame(TaskStatus::Suspended, $this->store->status($id));
        self::assertStringStartsWith('interrupted:', (string) $this->store->find($id)?->getError());
    }

    public function testAWrongStepIsRefused(): void
    {
        self::assertSame(PlayStepCommand::WRONG_ARGUMENTS, $this->step->execute(['taskId' => 'x', 'step' => 'first']));
    }

    private function started(string $script): string
    {
        $task = $this->manager->create('alice', 'Read', 'recap', "<?php\nconst PAGE = 1;\n", [['name' => 'read', 'script' => $script]]);
        $this->manager->start($task);

        return $task->getId();
    }
}
