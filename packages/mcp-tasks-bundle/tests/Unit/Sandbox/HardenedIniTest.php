<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Sandbox;

use Emagma\McpTasksBundle\Sandbox\HardenedIni;
use Emagma\McpTasksBundle\Sandbox\HardenedIniWarmer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(HardenedIni::class)]
#[CoversClass(HardenedIniWarmer::class)]
final class HardenedIniTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mcp-ini-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testEveryTaskSharesOneIniAndKeepsItsOwnDirectory(): void
    {
        $ini = new HardenedIni('64M', $this->directory);

        $first = $ini->command('/srv/tasks/a');
        $second = $ini->command('/srv/tasks/b');

        self::assertSame($first[3], $second[3], 'one ini for every task');
        self::assertCount(1, glob($this->directory . '/*.ini') ?: []);
        self::assertContains('open_basedir=/srv/tasks/a' . \PATH_SEPARATOR . HardenedIni::bootstrap(), $first);
        self::assertContains('open_basedir=/srv/tasks/b' . \PATH_SEPARATOR . HardenedIni::bootstrap(), $second);
        self::assertStringNotContainsString('open_basedir', (string) file_get_contents($first[3]));
    }

    public function testTheWarmerBuildsItBeforeAnyStep(): void
    {
        $ini = new HardenedIni('64M', $this->directory);

        (new HardenedIniWarmer($ini))->warmUp($this->directory);

        self::assertFileExists($ini->path());
        self::assertStringContainsString('memory_limit=64M', (string) file_get_contents($ini->path()));
    }

    public function testOnlyFunctionsAreDisabledInTheProcessTheInspectorChecksTheClasses(): void
    {
        $content = (string) file_get_contents((new HardenedIni('64M', $this->directory))->path());

        self::assertStringContainsString('disable_functions=', $content);
        self::assertStringNotContainsString('disable_classes', $content);
    }
}
