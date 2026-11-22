<?php

declare(strict_types=1);

namespace Emagma\McpScreensBundle\Tests\Unit;

use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Authorization\GrantsProviderInterface;
use Emagma\McpScreensBundle\App\ScreenApp;
use Emagma\McpScreensBundle\ScreenRegistry;
use Emagma\McpScreensBundle\ScreenResult;
use Emagma\McpScreensBundle\Tests\Unit\Fixture\NoteScreen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

#[CoversClass(ScreenApp::class)]
#[CoversClass(ScreenRegistry::class)]
#[CoversClass(ScreenResult::class)]
final class ScreenAppTest extends TestCase
{
    private NoteScreen $screen;

    protected function setUp(): void
    {
        $this->screen = new NoteScreen();
    }

    public function testAScreenOpensWithItsDataForTheModelAndItsFragmentForTheUser(): void
    {
        $result = $this->app('mcp:demo:note:write')->open('note', ['id' => 7]);

        self::assertTrue($result['ok']);
        self::assertSame(['note' => 'first'], $result['data']);
        self::assertSame(ScreenApp::SHOWN, $result['shown'], 'the model is told the user already sees the screen');
        self::assertSame('display', $result['mode']);
        self::assertTrue($result['can_edit']);
        self::assertStringContainsString('data-screen="note"', $result['html']);
        self::assertStringContainsString('data-params="&#x7B;&quot;id&quot;&#x3A;7&#x7D;"', $result['html'], 'the fragment carries what it is about');
        self::assertStringContainsString('<p>first</p>', $result['html']);
        self::assertArrayNotHasKey('typed', $result);
    }

    public function testWithoutTheWriteGrantsTheScreenIsReadOnlyAndCannotBeSaved(): void
    {
        $app = $this->app('mcp:demo:note:read');

        self::assertFalse($app->open('note', ['id' => 7])['can_edit']);
        self::assertStringEndsWith(ScreenApp::READ_ONLY, $app->open('note', ['id' => 7])['shown'], 'the model does not offer to edit');
        self::assertSame('display', $app->render('note', ['id' => 7], 'form')['mode'], 'no form for who cannot save');

        $refused = $app->submit('note', ['id' => 7], ['note' => 'second']);
        self::assertSame('forbidden', $refused['reason']);
        self::assertSame('first', $this->screen->note);
    }

    public function testWithoutItsReadGrantsAScreenIsRefusedAndNotListed(): void
    {
        $refused = $this->app('mcp:other:thing:read')->open('note', ['id' => 7]);

        self::assertFalse($refused['ok']);
        self::assertSame('forbidden', $refused['reason']);
        self::assertSame([], $refused['screens']);
        self::assertStringContainsString('role="alert"', $refused['html']);
    }

    public function testAnUnknownScreenAnswersWithTheOnesTheCallerMayOpen(): void
    {
        $refused = $this->app('mcp:demo:note:read')->open('nope');

        self::assertSame('unknown_screen', $refused['reason']);
        self::assertSame(['note' => 'One note. params: {id}.'], $refused['screens']);
    }

    public function testWhatAScreenCannotShowIsSaidNotThrown(): void
    {
        $refused = $this->app('mcp:demo:note:read')->open('note');

        self::assertSame('unavailable', $refused['reason']);
        self::assertSame('This screen needs params.id.', $refused['message']);
    }

    public function testAnInvalidFormComesBackWithItsErrorsAndWhatWasTyped(): void
    {
        $result = $this->app('mcp:demo:note:write')->submit('note', ['id' => 7], ['note' => '  ']);

        self::assertFalse($result['saved']);
        self::assertSame('form', $result['mode']);
        self::assertSame(['note' => 'Required.'], $result['errors']);
        self::assertStringContainsString('<input name="note" value="  ">', $result['html']);
        self::assertStringContainsString('message ko', $result['html']);
    }

    public function testASavedFormGoesBackToTheDisplayReadAgain(): void
    {
        $result = $this->app('mcp:demo:note:write')->submit('note', ['id' => 7], ['note' => 'second']);

        self::assertTrue($result['saved']);
        self::assertSame('display', $result['mode']);
        self::assertSame(['note' => 'second'], $result['data']);
        self::assertStringContainsString('message ok', $result['html']);
        self::assertStringContainsString('Note saved.', $result['html']);
    }

    private function app(string ...$grants): ScreenApp
    {
        $provider = $this->createStub(GrantsProviderInterface::class);
        $provider->method('grants')->willReturn(Grants::fromStrings(array_values($grants)));

        $files = new FilesystemLoader();
        $files->addPath(\dirname(__DIR__, 2) . '/templates', 'EmagmaMcpScreens');
        $twig = new Environment(new ChainLoader([$files, new ArrayLoader([
            'note.html.twig' => "{% if mode == 'form' %}<input name=\"note\" value=\"{{ typed.note ?? data.note }}\">{% else %}<p>{{ data.note }}</p>{% endif %}",
        ])]), ['strict_variables' => true]);

        return new ScreenApp(new ScreenRegistry([$this->screen]), $provider, $twig);
    }
}
