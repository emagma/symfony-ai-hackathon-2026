<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Tests\Unit\ProductTranslation;

use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
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
use Emagma\SymfonyAiHackathon2026Bundle\ProductTranslation\Tool\ProductTranslate;
use Emagma\SymfonyAiHackathon2026Bundle\ProductTranslation\TranslationTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

/**
 * product_translate prepares a task; its script is then played for real against a shop in memory.
 */
#[CoversClass(ProductTranslate::class)]
#[CoversClass(TranslationTask::class)]
final class ProductTranslateTest extends TestCase
{
    private string $directory;

    private InMemoryTaskStore $store;

    private RecordingOrchestrator $orchestrator;

    private FakeTools $tools;

    private TaskManager $manager;

    private TaskFiles $files;

    /** @var array<string, array<string, array<string, string>>> code => locale => texts */
    private array $products = [
        'A' => ['en_US' => ['name' => 'Apollo shirt', 'description' => 'A <b>shirt</b>.']],
        'B' => ['en_US' => ['name' => 'Boreal cap'], 'de_DE' => ['name' => 'Boreal Mütze']],
        'C' => ['en_US' => ['name' => '']],
    ];

    /** @var list<array<string, mixed>> what the model was asked */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mcp-translate-' . bin2hex(random_bytes(6));
        $this->store = new InMemoryTaskStore();
        $this->orchestrator = new RecordingOrchestrator();
        $this->files = new TaskFiles($this->directory);
        $this->tools = new FakeTools([
            'search_products' => fn (array $arguments): array => ['items' => array_map(static fn (string $code): array => ['code' => $code], array_keys($this->products)), 'hasMore' => false],
            // As the real tool: a locale without a translation falls back to another one, and
            // translatedLocales says which locales really have one.
            'get_products' => function (array $arguments): array {
                $code = $arguments['codes'][0];
                if (!isset($this->products[$code])) {
                    return ['found' => [], 'missing' => [$code]];
                }
                $translations = $this->products[$code];
                $texts = $translations[$arguments['locale']] ?? reset($translations);

                return ['found' => [['code' => $code] + $texts + ['translatedLocales' => array_keys($translations)]], 'missing' => []];
            },
            'llm_transform' => function (array $arguments): array {
                $this->asked[] = $arguments;
                $output = array_map(static fn (string $text): string => $text . ' (de)', $arguments['input']);

                return ['ok' => true, 'output' => $output + ['slug' => 'Apollo Hemd!']];
            },
            // As the real tool: the locale is the item's, the shop's default otherwise.
            'upsert_products' => function (array $arguments): array {
                $results = [];
                foreach ($arguments['items'] as $item) {
                    $code = $item['code'];
                    $locale = $item['locale'] ?? 'en_US';
                    unset($item['code'], $item['locale']);
                    $this->products[$code][$locale] = $item;
                    $results[$code] = ['ok' => true];
                }

                return ['ok' => true, 'results' => $results];
            },
        ]);
        $clock = new MockClock();
        $this->manager = new TaskManager($this->store, $this->files, new ScriptInspector(), $this->tools, new FakeScriptReader(), new FakeDelegation(), $this->orchestrator, $clock);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testItPreparesADraftTaskAndWritesNothing(): void
    {
        $answer = $this->tool()('de_DE');

        self::assertTrue($answer['ok']);
        self::assertSame('draft', $answer['status']);
        self::assertSame(['get_products', 'llm_transform', 'search_products', 'upsert_products'], $answer['manifest']);
        self::assertSame([], $this->tools->received);
        self::assertSame([], $this->orchestrator->started);

        $params = $this->files->params($answer['taskId']);
        self::assertStringContainsString("const SOURCE_LOCALE = 'en_US';", $params);
        self::assertStringContainsString("const TARGET_LOCALE = 'de_DE';", $params);
        self::assertStringContainsString('const TAXON = null;', $params);
        // The translation rules are frozen in the instruction the model will follow.
        $rules = trim((string) strtok((string) file_get_contents(TranslationTask::RULES), "\n"));
        self::assertStringContainsString(str_replace("'", "\\'", $rules), $params);
        self::assertStringContainsString('URL slug', $params);
    }

    public function testTheTaskTranslatesWhatHasNoTranslationAndNothingElse(): void
    {
        $task = $this->store->find($this->tool()('de_DE')['taskId']);
        self::assertNotNull($task);
        $this->manager->start($task);
        $this->work();

        self::assertSame(TaskStatus::Done, $task->getStatus(), (string) $task->getError());
        self::assertSame(
            ['slug' => 'apollo-hemd', 'name' => 'Apollo shirt (de)', 'description' => 'A <b>shirt</b>. (de)'],
            $this->products['A']['de_DE'],
        );
        // Already translated, and nothing to translate from: untouched, and the model is not paid for them.
        self::assertSame(['name' => 'Boreal Mütze'], $this->products['B']['de_DE']);
        self::assertArrayNotHasKey('de_DE', $this->products['C']);
        self::assertCount(1, $this->asked);
        self::assertSame(['name' => 'string', 'description' => 'string', 'slug' => 'string'], $this->asked[0]['outputSchema']);
    }

    public function testOnlyTheCodesGivenAreTranslated(): void
    {
        $answer = $this->tool()('de_DE', null, null, ['A', 'A']);
        self::assertStringContainsString("const CODES = ['A'];", $this->files->params($answer['taskId']));

        $task = $this->store->find($answer['taskId']);
        self::assertNotNull($task);
        $this->manager->start($task);
        $this->work();

        self::assertSame(TaskStatus::Done, $task->getStatus(), (string) $task->getError());
        self::assertNotContains('search_products', $this->tools->called());
        self::assertArrayHasKey('de_DE', $this->products['A']);
    }

    public function testATaxonNarrowsTheSearch(): void
    {
        $answer = $this->tool()('de_DE', null, "t-shirts' or");

        self::assertStringContainsString("const TAXON = 't-shirts\\' or';", $this->files->params($answer['taskId']));
    }

    public function testItRefusesWhatCannotBeTranslated(): void
    {
        self::assertSame('same_locale', $this->tool()('en_US')['reason']);
        self::assertSame('locale_not_found', $this->tool()('xx_XX')['reason']);
        self::assertSame('too_many_codes', $this->tool()('de_DE', null, null, array_map(strval(...), range(1, 201)))['reason']);
        self::assertSame('no_actor', $this->tool(null)('de_DE')['reason']);
    }

    private function tool(?string $actor = 'jean'): ProductTranslate
    {
        $client = $this->createStub(AdminApiClientInterface::class);
        $client->method('getDecodedMany')->willReturnCallback(static fn (array $routes): array => array_map(
            static fn (): array => ['ok' => true, 'body' => []],
            array_filter($routes, static fn (string $code): bool => 'xx_XX' !== $code, \ARRAY_FILTER_USE_KEY),
        ));
        $actors = $this->createStub(ActorProviderInterface::class);
        $actors->method('actor')->willReturn($actor);

        return new ProductTranslate(
            $client,
            new TranslationTask(),
            $this->manager,
            $actors,
            'en_US',
        );
    }

    private function work(): void
    {
        $clock = new MockClock();
        $delegation = new FakeDelegation();
        $notifier = new RecordingTaskNotifier();
        $this->orchestrator->play(
            new StepExecutor($this->store, new StepRunner(new ProcessSandbox(), $this->tools, $this->store, $this->files, $clock), $delegation, $notifier, $clock),
            new TaskLifecycle($this->store, $delegation, $notifier, $clock),
        );
    }
}
