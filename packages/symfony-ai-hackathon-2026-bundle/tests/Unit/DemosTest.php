<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Tests\Unit;

use Emagma\SymfonyAiHackathon2026Bundle\Command\DemosCommand;
use Emagma\SymfonyAiHackathon2026Bundle\Demo;
use Emagma\SymfonyAiHackathon2026Bundle\DemoRepository;
use Emagma\SymfonyAiHackathon2026Bundle\Tool\GetDemo;
use Emagma\SymfonyAiHackathon2026Bundle\Tool\ListDemos;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * On the tour the bundle ships: a demo that no longer reads, or that points at code that moved, fails here.
 */
#[CoversClass(DemoRepository::class)]
#[CoversClass(Demo::class)]
#[CoversClass(ListDemos::class)]
#[CoversClass(GetDemo::class)]
#[CoversClass(DemosCommand::class)]
final class DemosTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../../..';

    public function testTheTourReadsInOrderAndEveryDemoSaysWhatItShows(): void
    {
        $demos = $this->demos()->all();

        self::assertGreaterThanOrEqual(8, \count($demos));
        self::assertSame(range(0, \count($demos) - 1), array_map(static fn (Demo $demo): int => $demo->position, $demos), 'numbered without a gap');
        self::assertSame('chat', $demos[0]->name);

        foreach ($demos as $demo) {
            self::assertNotSame('', $demo->guide, $demo->name);
            self::assertNotSame([], $demo->symfonyAi, $demo->name . ' names no Symfony AI notion');
            self::assertNotSame([], $demo->code, $demo->name);
        }
    }

    public function testTheCodeADemoPointsAtExists(): void
    {
        if (!is_dir(self::ROOT . '/apps/mcp-server')) {
            self::markTestSkipped('The demos point at the files of the monorepo: this package is checked on its own.');
        }

        foreach ($this->demos()->all() as $demo) {
            foreach ($demo->code as $path) {
                self::assertFileExists(self::ROOT . '/' . $path, \sprintf('demo "%s"', $demo->name));
            }
        }
    }

    public function testTheListIsAMenuAndADemoComesWithTheNextOne(): void
    {
        $listed = (new ListDemos($this->demos()))();
        self::assertSame($listed['total'], \count($listed['items']));
        self::assertSame(['name', 'position', 'title', 'user', 'symfonyAi'], array_keys($listed['items'][0]));

        $demo = (new GetDemo($this->demos()))('screens');
        self::assertTrue($demo['found']);
        self::assertSame('Screens in the conversation', $demo['title']);
        self::assertStringContainsString('Celestial_Harmony_T_Shirt', $demo['prompts'][0]);
        self::assertSame('skills', $demo['next']['name']);

        // The last demo of the tour, whichever it is, leads nowhere.
        $last = (new GetDemo($this->demos()))($listed['items'][\count($listed['items']) - 1]['name']);
        self::assertTrue($last['found']);
        self::assertNull($last['next']);
    }

    public function testAnUnknownDemoAnswersWithTheOnesThatExist(): void
    {
        $unknown = (new GetDemo($this->demos()))('nope');

        self::assertFalse($unknown['found']);
        self::assertSame('not_found', $unknown['reason']);
        self::assertContains('procedures', $unknown['names']);
    }

    public function testTheCommandShowsTheTourOrOneDemo(): void
    {
        $tester = new CommandTester(new DemosCommand($this->demos()));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('background-task', $tester->getDisplay());

        self::assertSame(0, $tester->execute(['name' => 'fraud-agent']));
        self::assertStringContainsString('hackathon:agent:fraud', $tester->getDisplay());

        self::assertSame(1, $tester->execute(['name' => 'nope']));
    }

    public function testAFileWithoutATitleIsABugNotAnEmptyDemo(): void
    {
        $directory = sys_get_temp_dir() . '/hackathon-demos-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/00-broken.md', "---\nuser: admin\n---\nNo title.\n");

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('has no title');
            (new DemoRepository($directory))->all();
        } finally {
            unlink($directory . '/00-broken.md');
            rmdir($directory);
        }
    }

    private function demos(): DemoRepository
    {
        return new DemoRepository(\dirname(__DIR__, 2) . '/demos');
    }
}
