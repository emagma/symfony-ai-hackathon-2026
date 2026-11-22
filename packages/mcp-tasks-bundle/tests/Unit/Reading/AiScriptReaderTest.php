<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Reading;

use Emagma\McpTasksBundle\Reading\AiScriptReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Test\MockPlatformFactory;

#[CoversClass(AiScriptReader::class)]
final class AiScriptReaderTest extends TestCase
{
    public function testTheReadingIsAskedInEnglishWhateverTheLanguageTheScriptsHandle(): void
    {
        $sent = null;
        $platform = MockPlatformFactory::createPlatform(static function (Model $model, array|string|object $input) use (&$sent): string {
            $sent = json_encode($input, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

            return "  **Translate the products into Italian**\n";
        });
        $reader = new AiScriptReader($platform, 'reader-model', 1000);

        $reading = $reader->read(
            'Traduci i prodotti in italiano',
            "<?php\nconst TARGET_LOCALE = 'it_IT';\n",
            [['name' => 'translate', 'script' => "<?php\n// Traduci ogni prodotto.\n"]],
        );

        self::assertSame('**Translate the products into Italian**', $reading);
        self::assertIsString($sent);
        self::assertStringContainsString('Write in English, always', $sent);
        self::assertStringContainsString('Write the reading in English.', $sent);
        self::assertStringNotContainsString('Write in the language of the objective', $sent);
        self::assertStringContainsString('Traduci i prodotti in italiano', $sent);
    }
}
