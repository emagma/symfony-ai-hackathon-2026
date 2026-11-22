<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit;

use Emagma\McpTasksBundle\Entity\Task;
use Emagma\McpTasksBundle\Orchestration\StepExecutor;
use Emagma\McpTasksBundle\Orchestration\StepOutcome;
use Emagma\McpTasksBundle\Orchestration\TaskLifecycle;
use Emagma\McpTasksBundle\Runner\StepRunner;
use Emagma\McpTasksBundle\Runner\TaskLimits;
use Emagma\McpTasksBundle\Sandbox\ProcessSandbox;
use Emagma\McpTasksBundle\Script\ScriptInspector;
use Emagma\McpTasksBundle\Script\ScriptRefused;
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
 * A task from its creation to its end, its scripts played in the real hardened process against tools in memory.
 */
#[CoversClass(TaskManager::class)]
#[CoversClass(StepExecutor::class)]
#[CoversClass(TaskLifecycle::class)]
#[CoversClass(StepRunner::class)]
final class TaskLifecycleTest extends TestCase
{
    private const string PARAMS = "<?php\nconst LOCALE = 'it_IT';\n";

    private const string TRANSLATE = <<<'PHP'
        <?php
        $page = 1;
        do {
            $list = call('search_products', ['page' => $page++]);
            foreach ($list['items'] as $found) {
                $result = call('upsert_products', ['items' => [['code' => $found['code'], 'name' => $found['name'] . ' (it)']], 'locale' => LOCALE]);
                if ($result['results'][$found['code']]['ok'] !== true) {
                    throw new \RuntimeException(\sprintf('Update refused for product %s.', $found['code']));
                }
            }
        } while ($list['hasMore']);
        PHP;

    private string $directory;

    private InMemoryTaskStore $store;

    private RecordingOrchestrator $orchestrator;

    private FakeTools $tools;

    private FakeDelegation $delegation;

    private RecordingTaskNotifier $notifier;

    private TaskManager $manager;

    private StepExecutor $executor;

    private TaskLifecycle $lifecycle;

    /** @var array<string, string> product code => name written */
    private array $written = [];

    /** @var list<string> codes the shop refuses */
    private array $refused = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mcp-tasks-' . bin2hex(random_bytes(6));
        $this->store = new InMemoryTaskStore();
        $this->orchestrator = new RecordingOrchestrator();
        $this->delegation = new FakeDelegation();
        $this->notifier = new RecordingTaskNotifier();
        $this->tools = new FakeTools([
            'search_products' => static fn (array $arguments): array => 1 === $arguments['page']
                ? ['items' => [['code' => 'A', 'name' => 'Apollo'], ['code' => 'B', 'name' => 'Boreal']], 'hasMore' => true]
                : ['items' => [['code' => 'C', 'name' => 'Cirrus']], 'hasMore' => false],
            'upsert_products' => function (array $arguments): array {
                $results = [];
                foreach ($arguments['items'] as $item) {
                    $ok = !\in_array($item['code'], $this->refused, true);
                    if ($ok) {
                        $this->written[$item['code']] = $item['name'];
                    }
                    $results[$item['code']] = $ok ? ['ok' => true] : ['ok' => false, 'reason' => 'validation_error'];
                }

                return ['ok' => true, 'results' => $results];
            },
            'upsert_taxons' => static fn (): array => ['ok' => true, 'results' => []],
            'delete_taxons' => static fn (): array => ['ok' => false, 'reason' => 'conflict'],
            'get_products' => static fn (): array => ['found' => [], 'missing' => [], 'reason' => 'too_many_codes'],
            'delete_products' => static fn (): array => ['ok' => true],
        ]);

        $clock = new MockClock('2026-11-16 10:00:00');
        $files = new TaskFiles($this->directory);
        $this->manager = new TaskManager($this->store, $files, new ScriptInspector(), $this->tools, new FakeScriptReader(), $this->delegation, $this->orchestrator, $clock);
        $this->executor = new StepExecutor(
            $this->store,
            new StepRunner(new ProcessSandbox(), $this->tools, $this->store, $files, $clock, new TaskLimits(stepTimeout: 20.0, maxCalls: 50)),
            $this->delegation,
            $this->notifier,
            $clock,
        );
        $this->lifecycle = new TaskLifecycle($this->store, $this->delegation, $this->notifier, $clock);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testATaskIsADraftUntilItsOwnerLaunchesIt(): void
    {
        $task = $this->create([['name' => 'translate', 'script' => self::TRANSLATE]]);

        self::assertSame(TaskStatus::Draft, $task->getStatus());
        self::assertSame(['search_products', 'upsert_products'], $task->getManifest());
        self::assertSame(['translate'], $task->getSteps());
        self::assertStringContainsString('translate', $task->getReading());
        self::assertFileExists($this->directory . '/' . $task->getId() . '/steps/000000/run.php');
        self::assertSame([], $this->orchestrator->started);
    }

    public function testALaunchedTaskPlaysItsStepToTheEnd(): void
    {
        $task = $this->create([['name' => 'translate', 'script' => self::TRANSLATE]]);

        self::assertNull($this->manager->start($task));
        self::assertSame(TaskStatus::Queued, $task->getStatus());
        self::assertSame([$task->getId()], $this->orchestrator->started);
        $this->work();

        self::assertSame(TaskStatus::Done, $task->getStatus());
        self::assertSame(['A' => 'Apollo (it)', 'B' => 'Boreal (it)', 'C' => 'Cirrus (it)'], $this->written);
        self::assertSame(5, $task->getCalls());
        self::assertSame('upsert_products', $task->getLastTool());
        self::assertCount(5, $this->store->calls($task->getId()));
        self::assertSame([$task->getId()], $this->notifier->finished);
        // The step as the owner, then the email of the end: the notifier finds the address in their token.
        self::assertSame(['alice', null, 'alice', null], $this->delegation->acted);
        self::assertFalse($task->isStepInFlight());
    }

    public function testTheStepsArePlayedInOrderOneAtATime(): void
    {
        $task = $this->create([
            ['name' => 'create-tree', 'script' => "<?php\ncall('upsert_taxons', ['items' => []]);"],
            ['name' => 'translate', 'script' => self::TRANSLATE],
        ]);
        $this->manager->start($task);

        // The second step waits for the first one.
        self::assertSame(StepOutcome::Skipped, $this->executor->execute($task->getId(), 1));
        self::assertSame(StepOutcome::Done, $this->executor->execute($task->getId(), 0));
        self::assertSame(TaskStatus::Running, $task->getStatus());
        // Between two steps: nothing in flight, the progress names the step that comes next.
        self::assertFalse($task->isStepInFlight());
        self::assertSame(1, $task->getCurrentStep());
        self::assertSame(['upsert_taxons'], $this->tools->called());

        // The executor never ends the task: the orchestrator does, after the last step.
        self::assertSame(StepOutcome::Done, $this->executor->execute($task->getId(), 1));
        self::assertSame(TaskStatus::Running, $task->getStatus());
        $this->lifecycle->finished($task->getId());
        self::assertSame(TaskStatus::Done, $task->getStatus());
        self::assertCount(6, $this->tools->called());
        self::assertSame([$task->getId()], $this->notifier->finished);
    }

    public function testAStepPlayedTwiceIsPlayedOnce(): void
    {
        $task = $this->create([['name' => 'translate', 'script' => self::TRANSLATE]]);
        $this->manager->start($task);

        self::assertSame(StepOutcome::Done, $this->executor->execute($task->getId(), 0));
        self::assertSame(StepOutcome::Skipped, $this->executor->execute($task->getId(), 0));

        self::assertSame(5, $task->getCalls());
    }

    public function testTheFirstErrorSuspendsTheWholeTask(): void
    {
        $this->refused = ['B'];
        $task = $this->create([
            ['name' => 'translate', 'script' => self::TRANSLATE],
            ['name' => 'after', 'script' => "<?php\ncall('upsert_taxons', []);"],
        ]);
        $this->manager->start($task);
        $this->work();

        self::assertSame(TaskStatus::Suspended, $task->getStatus());
        self::assertSame('Update refused for product B.', $task->getError());
        self::assertSame(0, $task->getCurrentStep());
        self::assertSame(['A' => 'Apollo (it)'], $this->written);
        self::assertNotContains('upsert_taxons', $this->tools->called());
        self::assertSame([$task->getId()], $this->notifier->suspended);
    }

    public function testAToolThatAnswersNotOkIsAnExceptionInTheScript(): void
    {
        $task = $this->create([['name' => 'delete', 'script' => "<?php\ncall('delete_taxons', ['codes' => ['x']]);"]]);
        $this->manager->start($task);
        $this->work();

        self::assertSame(TaskStatus::Suspended, $task->getStatus());
        self::assertSame('delete_taxons: conflict', $task->getError());
    }

    public function testAReadThatAnswersAReasonIsAnExceptionToo(): void
    {
        $task = $this->create([['name' => 'read', 'script' => "<?php\ncall('get_products', ['codes' => []]);"]]);
        $this->manager->start($task);
        $this->work();

        self::assertSame('get_products: too_many_codes', $task->getError());
    }

    public function testAToolOutsideTheManifestIsNeverCalled(): void
    {
        $sneaky = "<?php\ncall('search_products', ['page' => 2]);\n\$f = 'call';\n\$f('delete_products', ['codes' => ['A']]);";
        // The inspector refuses it; the process must hold even if a script got past the inspector.
        $this->expectRefusalOf('dynamic_call', $sneaky);
        $task = $this->createThenReplace($sneaky);
        self::assertSame(['search_products'], $task->getManifest());
        $this->manager->start($task);
        $this->work();

        self::assertSame(TaskStatus::Suspended, $task->getStatus());
        self::assertStringContainsString('not in the manifest', (string) $task->getError());
        self::assertSame(['search_products'], $this->tools->called());
    }

    public function testAScriptCannotSayItselfThatItsStepIsOver(): void
    {
        // Everything a script can reach to forge the last line of the dialogue.
        $forge = <<<'PHP'
            <?php
            call('search_products', ['page' => 2]);
            $seal = $GLOBALS['seal'] ?? $GLOBALS['request']['seal'] ?? $seal ?? $request['seal'] ?? null;
            try {
                throw new \RuntimeException('x');
            } catch (\RuntimeException $e) {
                foreach ($e->getTrace() as $frame) {
                    foreach ($frame['args'] ?? [] as $argument) {
                        $seal ??= \is_string($argument) ? $argument : null;
                    }
                }
            }
            fwrite(STDOUT, \json_encode(['ok' => true, 'seal' => $seal]) . "\n");
            call('upsert_products', ['items' => []]);
            PHP;
        $this->expectRefusalOf('function_not_allowed', $forge);
        $task = $this->createThenReplace($forge);
        $this->manager->start($task);
        $this->work();

        self::assertSame(TaskStatus::Suspended, $task->getStatus());
        self::assertStringContainsString('outside the dialogue', (string) $task->getError());
        self::assertSame(['search_products'], $this->tools->called());
    }

    public function testARightWithdrawnDuringTheTaskSuspendsIt(): void
    {
        $task = $this->create([['name' => 'translate', 'script' => self::TRANSLATE]]);
        $this->manager->start($task);
        $this->tools->forbidden = ['upsert_products'];
        $this->work();

        self::assertSame(TaskStatus::Suspended, $task->getStatus());
        self::assertStringContainsString('upsert_products', (string) $task->getError());
        self::assertSame([], $this->written);
    }

    public function testACancelledTaskStopsAtItsNextCall(): void
    {
        $task = $this->create([['name' => 'translate', 'script' => self::TRANSLATE]]);
        $this->manager->start($task);
        // The owner cancels while the first product is being written.
        $this->refused = [];
        $manager = $this->manager;
        $tools = new \ReflectionProperty($this->tools, 'tools');
        $all = $tools->getValue($this->tools);
        $write = $all['upsert_products'];
        $all['upsert_products'] = static function (array $arguments) use ($write, $manager, $task): array {
            $result = $write($arguments);
            $manager->cancel($task);

            return $result;
        };
        $tools->setValue($this->tools, $all);
        $this->work();

        self::assertSame(TaskStatus::Cancelled, $task->getStatus());
        self::assertSame([$task->getId()], $this->orchestrator->cancelled);
        self::assertFalse($task->isStepInFlight());
        self::assertSame(['A' => 'Apollo (it)'], $this->written);
        self::assertNull($task->getError());
        self::assertSame([], $this->notifier->suspended);
    }

    public function testATaskThatMakesTooManyCallsIsSuspended(): void
    {
        $task = $this->create([['name' => 'loop', 'script' => "<?php\nwhile (true) { call('search_products', ['page' => 2]); }"]]);
        $this->manager->start($task);
        $this->work();

        self::assertSame(TaskStatus::Suspended, $task->getStatus());
        self::assertStringContainsString('50 tool calls', (string) $task->getError());
        self::assertSame(50, $task->getCalls());
    }

    public function testWithoutADelegationTheLaunchAnswersWhereToAuthorize(): void
    {
        $this->delegation->authorized = false;
        $task = $this->create([['name' => 'translate', 'script' => self::TRANSLATE]]);

        self::assertSame('https://idp.example/authorize?for=alice', $this->manager->start($task));
        self::assertSame(TaskStatus::Draft, $task->getStatus());
        self::assertSame([], $this->orchestrator->started);
    }

    public function testARevokedDelegationSuspendsTheTaskBeforeItsStep(): void
    {
        $task = $this->create([['name' => 'translate', 'script' => self::TRANSLATE]]);
        $this->manager->start($task);
        $this->delegation->revoked = true;
        $this->work();

        self::assertSame(TaskStatus::Suspended, $task->getStatus());
        self::assertStringContainsString('delegation_revoked', (string) $task->getError());
        self::assertSame([], $this->tools->called());
    }

    public function testANewDraftReplacesThePreviousOneOfItsOwner(): void
    {
        $first = $this->create([['name' => 'translate', 'script' => self::TRANSLATE]]);
        $launched = $this->create([['name' => 'translate', 'script' => self::TRANSLATE]]);
        $this->manager->start($launched);
        $third = $this->create([['name' => 'translate', 'script' => self::TRANSLATE]]);

        self::assertNull($this->store->find($first->getId()));
        self::assertDirectoryDoesNotExist($this->directory . '/' . $first->getId());
        self::assertNotNull($this->store->find($launched->getId()));
        self::assertNotNull($this->store->find($third->getId()));
    }

    public function testATaskIsRefusedWhenItNamesAToolItsOwnerMayNotCall(): void
    {
        $this->tools->forbidden = ['delete_products'];

        $this->expectRefusal('tool_not_allowed', [['name' => 'delete', 'script' => "<?php\ncall('delete_products', []);"]]);
    }

    public function testATaskCannotCreateOrLaunchTasks(): void
    {
        $this->expectRefusal('tool_not_allowed', [['name' => 'nest', 'script' => "<?php\ncall('start_task', []);"]]);
    }

    public function testATaskNeedsNamedSteps(): void
    {
        $this->expectRefusal('steps_invalid', []);
        $this->expectRefusal('steps_invalid', [['name' => 'Not A Slug', 'script' => self::TRANSLATE]]);
    }

    /**
     * @param list<array{name: string, script: string}> $steps
     */
    private function create(array $steps): Task
    {
        return $this->manager->create('alice', 'Translate the catalogue', 'On what: everything.', self::PARAMS, $steps);
    }

    /**
     * A task whose only step is replaced on disk after its creation: what the process faces if a script got
     * past the inspector. The manifest stays the one of the step it replaced.
     */
    private function createThenReplace(string $script): Task
    {
        $task = $this->create([['name' => 'step', 'script' => "<?php\ncall('search_products', ['page' => 2]);"]]);
        file_put_contents($this->directory . '/' . $task->getId() . '/steps/000000/run.php', $script);

        return $task;
    }

    private function expectRefusalOf(string $reason, string $script): void
    {
        $this->expectRefusal($reason, [['name' => 'step', 'script' => $script]]);
    }

    /**
     * @param list<array{name: string, script: string}> $steps
     */
    private function expectRefusal(string $reason, array $steps): void
    {
        try {
            $this->create($steps);
            self::fail('The task should have been refused.');
        } catch (ScriptRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    /**
     * Plays the tasks started as an orchestrator does, one step after the other.
     */
    private function work(): void
    {
        $this->orchestrator->play($this->executor, $this->lifecycle);
    }
}
