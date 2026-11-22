<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle\Tests\Integration;

use Emagma\McpCoreBundle\Instruction\McpInstructions;
use Emagma\McpScreensBundle\App\ScreenApp;
use Emagma\McpScreensBundle\EmagmaMcpScreensBundle;
use Emagma\McpScreensBundle\ScreenRegistry;
use Emagma\McpScreensBundle\Tests\Integration\Fixture\TestKernel;
use Emagma\McpScreensBundle\Tests\Unit\Fixture\NoteScreen;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(EmagmaMcpScreensBundle::class)]
final class ScreensBundleTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testAServiceImplementingTheContractIsTaggedAndReachedByName(): void
    {
        self::bootKernel();

        $registry = self::getContainer()->get('test.screen_registry');
        self::assertInstanceOf(ScreenRegistry::class, $registry);
        self::assertInstanceOf(NoteScreen::class, $registry->get('note'));
    }

    public function testTheScreensAreListedInTheServersInstructions(): void
    {
        self::bootKernel();

        $instructions = self::getContainer()->get('test.instructions');
        self::assertInstanceOf(McpInstructions::class, $instructions);
        self::assertStringContainsString("## Screens\n", (string) $instructions->text());
        self::assertStringContainsString('- note: One note. params: {id}.', (string) $instructions->text());
    }

    public function testTheBundleOwnTemplatesAreServedUnderItsNamespace(): void
    {
        self::bootKernel();

        $app = self::getContainer()->get('test.screen_app');
        self::assertInstanceOf(ScreenApp::class, $app);

        $refusal = $app->open('nope');

        self::assertSame('unknown_screen', $refusal['reason']);
        self::assertStringContainsString('role="alert"', $refusal['html']);
        self::assertSame(['note' => 'One note. params: {id}.'], $refusal['screens']);
    }
}
