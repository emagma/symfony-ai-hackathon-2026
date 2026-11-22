<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Tool;

use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpTasksBundle\Llm\LlmTransformerInterface;
use Emagma\McpTasksBundle\Llm\OutputSchema;
use Emagma\McpTasksBundle\Script\ScriptInspector;
use Emagma\McpTasksBundle\Script\ScriptLinter;
use Emagma\McpTasksBundle\Script\TaskFiles;
use Emagma\McpTasksBundle\TaskManager;
use Emagma\McpTasksBundle\TaskStatus;
use Emagma\McpTasksBundle\TaskView;
use Emagma\McpTasksBundle\Testing\FakeDelegation;
use Emagma\McpTasksBundle\Testing\FakeScriptReader;
use Emagma\McpTasksBundle\Testing\FakeTools;
use Emagma\McpTasksBundle\Testing\InMemoryTaskStore;
use Emagma\McpTasksBundle\Testing\RecordingOrchestrator;
use Emagma\McpTasksBundle\Tests\Unit\Script\ScriptLinterTest;
use Emagma\McpTasksBundle\Tool\CancelTask;
use Emagma\McpTasksBundle\Tool\CreateTask;
use Emagma\McpTasksBundle\Tool\GetTask;
use Emagma\McpTasksBundle\Tool\ListTasks;
use Emagma\McpTasksBundle\Tool\LlmTransform;
use Emagma\McpTasksBundle\Tool\RevokeTaskAuthorization;
use Emagma\McpTasksBundle\Tool\StartTask;
use Emagma\McpTasksBundle\Tool\TaskAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(CreateTask::class)]
#[CoversClass(StartTask::class)]
#[CoversClass(ListTasks::class)]
#[CoversClass(GetTask::class)]
#[CoversClass(CancelTask::class)]
#[CoversClass(RevokeTaskAuthorization::class)]
#[CoversClass(LlmTransform::class)]
#[CoversClass(TaskAccess::class)]
#[CoversClass(TaskView::class)]
final class TaskToolsTest extends TestCase
{
    private const string PARAMS = "<?php\nconst LOCALE = 'it_IT';\n";

    private const array STEPS = [['name' => 'translate', 'script' => "<?php\ncall('search_products', ['locale' => LOCALE]);"]];

    private string $directory;

    private InMemoryTaskStore $store;

    private RecordingOrchestrator $orchestrator;

    private FakeDelegation $delegation;

    private TaskManager $manager;

    private TaskFiles $files;

    private string $actor = 'alice';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mcp-tasks-' . bin2hex(random_bytes(6));
        $this->store = new InMemoryTaskStore();
        $this->orchestrator = new RecordingOrchestrator();
        $this->delegation = new FakeDelegation();
        $this->files = new TaskFiles($this->directory);
        $this->manager = new TaskManager(
            $this->store,
            $this->files,
            new ScriptInspector(),
            new FakeTools(['search_products' => static fn (): array => ['items' => []]]),
            new FakeScriptReader(),
            $this->delegation,
            $this->orchestrator,
            new MockClock('2026-11-16 10:00:00'),
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testCreatingATaskAnswersItsReadingAndItsManifest(): void
    {
        $created = $this->create();

        self::assertTrue($created['ok']);
        self::assertSame('draft', $created['status']);
        self::assertSame(['search_products'], $created['manifest']);
        self::assertIsString($created['taskId']);
        // The id first: shown with the reading, it is what the human and the model name the task by.
        self::assertSame("Task {$created['taskId']}\n\nTranslate: translate", $created['reading']);
    }

    public function testAToolNamedWithTheClientsPrefixIsRenamedBeforeTheReading(): void
    {
        $created = (new CreateTask($this->manager, $this->access()))('Translate', 'recap', "const LOCALE = 'it_IT';", [
            ['name' => 'translate', 'script' => "call('shop_search_products', ['locale' => LOCALE]);"],
        ]);

        self::assertTrue($created['ok'], json_encode($created) ?: '');
        self::assertSame(['shop_search_products' => 'search_products'], $created['renamed']);
        self::assertSame(['search_products'], $created['manifest']);
        // The opening tags the model forgot are added, and the human validates the real name.
        $scripts = (new GetTask($this->access(), new TaskView($this->store, $this->files)))($created['taskId'], 'scripts');
        self::assertStringStartsWith('<?php', $scripts['params']);
        self::assertStringContainsString("call('search_products', ['locale' => LOCALE]);", $scripts['steps'][0]['script']);
    }

    public function testAConfiguredLinterFixesTheFilesBeforeTheyAreInspectedAndRead(): void
    {
        if ('' === ScriptLinterTest::ecs()) {
            self::markTestSkipped('No ECS binary: run composer install in the package.');
        }
        $manager = new TaskManager(
            $this->store,
            $this->files,
            new ScriptInspector(),
            new FakeTools(['search_products' => static fn (): array => ['items' => []]]),
            new FakeScriptReader(),
            new FakeDelegation(),
            new RecordingOrchestrator(),
            new MockClock(),
            new ScriptLinter(ScriptLinterTest::ecs(), ScriptLinterTest::COMMAND),
        );

        $created = (new CreateTask($manager, $this->access()))('Translate', 'recap', self::PARAMS, [
            ['name' => 'translate', 'script' => "<?php\n\$x = call('search_products', []);\nif ([] === \$x['items']) {\n    throw new RuntimeException(sprintf('none %d', 1));\n}"],
        ]);

        self::assertTrue($created['ok'], json_encode($created) ?: '');
        $scripts = (new GetTask($this->access(), new TaskView($this->store, $this->files)))($created['taskId'], 'scripts');
        self::assertStringContainsString('throw new \\RuntimeException(\\sprintf(', $scripts['steps'][0]['script']);
    }

    public function testARefusedTaskLeavesNoFile(): void
    {
        $refused = (new CreateTask($this->manager, $this->access()))('Translate', 'recap', self::PARAMS, [['name' => 'x', 'script' => "<?php\neval('1');"]]);

        self::assertFalse($refused['ok']);
        self::assertSame([], glob($this->directory . '/*') ?: []);
    }

    public function testEveryToolTheServerCannotCallIsNamedAtOnce(): void
    {
        $refused = (new CreateTask($this->manager, $this->access()))('Translate', 'recap', self::PARAMS, [
            ['name' => 'a', 'script' => "<?php\ncall('serch_products', []);\ncall('delete_everything', []);"],
        ]);

        self::assertSame('tool_not_allowed', $refused['reason']);
        self::assertStringContainsString('"serch_products" (did you mean search_products?)', $refused['message']);
        self::assertStringContainsString('"delete_everything"', $refused['message']);
    }

    public function testARefusedScriptSaysWhy(): void
    {
        $refused = (new CreateTask($this->manager, $this->access()))('Translate', 'recap', self::PARAMS, [['name' => 'x', 'script' => "<?php\neval('1');"]]);

        self::assertFalse($refused['ok']);
        self::assertSame('construct_forbidden', $refused['reason']);
        self::assertStringContainsString('eval', $refused['message']);
    }

    public function testNobodyCreatesATaskForNobody(): void
    {
        $this->actor = '';

        self::assertSame('no_actor', $this->create()['reason']);
    }

    public function testStartingShowsTheReadingAndNeedsAConfirmation(): void
    {
        $id = $this->create()['taskId'];
        $start = new StartTask($this->manager, $this->access(), new TaskView($this->store, $this->files));

        $preview = $start($id);
        self::assertFalse($preview['ok']);
        self::assertSame('elicitation_unavailable_preview_only', $preview['reason']);
        self::assertStringContainsString('Translate: translate', $preview['preview']);
        self::assertSame([], $this->orchestrator->started);

        $started = $start($id, true);
        self::assertTrue($started['ok']);
        self::assertSame('queued', $started['started']['status']);
        self::assertSame([$id], $this->orchestrator->started);

        self::assertSame('not_a_draft', $start($id, true)['reason']);
    }

    public function testStartingWithoutADelegationAnswersTheLink(): void
    {
        $this->delegation->authorized = false;
        $id = $this->create()['taskId'];

        $answer = (new StartTask($this->manager, $this->access(), new TaskView($this->store, $this->files)))($id, true);

        self::assertSame('authorization_required', $answer['reason']);
        self::assertSame('https://idp.example/authorize?for=alice', $answer['url']);
        self::assertSame(TaskStatus::Draft, $this->store->find($id)?->getStatus());

        // Before any confirmation: the human is not asked to agree to a launch that cannot happen yet.
        self::assertSame('authorization_required', (new StartTask($this->manager, $this->access(), new TaskView($this->store, $this->files)))($id)['reason']);
    }

    public function testATaskIsOnlySeenByItsOwner(): void
    {
        $id = $this->create()['taskId'];
        $view = new TaskView($this->store, $this->files);

        self::assertTrue((new GetTask($this->access(), $view))($id)['found']);
        self::assertSame(1, (new ListTasks($this->store, $this->access(), $view))()['total']);

        $this->actor = 'bob';
        self::assertSame(['found' => false, 'taskId' => $id], (new GetTask($this->access(), $view))($id));
        self::assertSame(0, (new ListTasks($this->store, $this->access(), $view))()['total']);
        self::assertSame('not_found', (new CancelTask($this->manager, $this->access(), $view))($id)['reason']);
        self::assertSame('not_found', (new StartTask($this->manager, $this->access(), $view))($id, true)['reason']);
    }

    public function testGettingATaskSaysWhereItStands(): void
    {
        $id = $this->create()['taskId'];
        $view = new TaskView($this->store, $this->files);
        $task = $this->store->find($id);
        self::assertNotNull($task);
        $task->setStatus(TaskStatus::Running);
        $task->countCall('search_products', new \DateTimeImmutable('2026-11-16 10:00:05'));

        $detail = (new GetTask($this->access(), $view))($id);
        self::assertSame('running', $detail['status']);
        self::assertSame(['step' => 1, 'steps' => 1, 'name' => 'translate', 'calls' => 1, 'lastTool' => 'search_products', 'lastCallAt' => '2026-11-16T10:00:05+00:00'], $detail['progress']);
        self::assertSame("Task {$id}\n\nTranslate: translate", $detail['reading']);

        $scripts = (new GetTask($this->access(), $view))($id, 'scripts');
        // As written on disk: in strict mode.
        self::assertSame(TaskFiles::strict(self::PARAMS), $scripts['params']);
        self::assertSame(TaskFiles::strict(self::STEPS[0]['script']), $scripts['steps'][0]['script']);

        self::assertSame([], (new GetTask($this->access(), $view))($id, 'calls')['calls']);
    }

    public function testACancelledTaskIsKept(): void
    {
        $id = $this->create()['taskId'];
        $view = new TaskView($this->store, $this->files);

        self::assertSame('cancelled', (new CancelTask($this->manager, $this->access(), $view))($id)['cancelled']['status']);
        self::assertSame('already_over', (new CancelTask($this->manager, $this->access(), $view))($id)['reason']);
        self::assertSame('cancelled', (new GetTask($this->access(), $view))($id)['status']);
        self::assertSame(TaskFiles::strict(self::PARAMS), (new GetTask($this->access(), $view))($id, 'scripts')['params']);
    }

    public function testRevokingTheAuthorization(): void
    {
        self::assertTrue((new RevokeTaskAuthorization($this->delegation, $this->access()))()['ok']);
        self::assertFalse($this->delegation->authorized);
    }

    public function testLlmTransformAnswersATypedOutput(): void
    {
        $model = new class() implements LlmTransformerInterface {
            public string $answer = '{"gender": "women", "new": true}';

            public function transform(string $instruction, string $input, OutputSchema $schema): string
            {
                return $this->answer;
            }
        };
        $tool = new LlmTransform($model);

        self::assertSame(
            ['ok' => true, 'output' => ['gender' => 'women', 'new' => true]],
            $tool('Classify.', ['name' => 'Dress'], ['gender' => 'enum[men,women]', 'new' => 'bool', 'note' => 'string?']),
        );

        $model->answer = '{"gender": "robots", "new": true}';
        $rejected = $tool('Classify.', ['name' => 'Dress'], ['gender' => 'enum[men,women]', 'new' => 'bool']);
        self::assertSame('output_rejected', $rejected['reason']);

        self::assertSame('schema_invalid', $tool('Classify.', 'x', ['gender' => 'whatever'])['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function create(): array
    {
        return (new CreateTask($this->manager, $this->access()))('Translate', 'On what: everything.', self::PARAMS, self::STEPS);
    }

    private function access(): TaskAccess
    {
        $actor = $this->actor;

        return new TaskAccess($this->store, new class($actor) implements ActorProviderInterface {
            public function __construct(private string $actor)
            {
            }

            public function actor(): ?string
            {
                return '' === $this->actor ? null : $this->actor;
            }
        });
    }
}
