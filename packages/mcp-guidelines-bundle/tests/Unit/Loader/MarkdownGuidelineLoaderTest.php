<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle\Tests\Unit\Loader;

use Emagma\McpGuidelinesBundle\EmagmaMcpGuidelinesBundle;
use Emagma\McpGuidelinesBundle\Loader\MarkdownGuidelineLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownGuidelineLoader::class)]
final class MarkdownGuidelineLoaderTest extends TestCase
{
    public function testAFileIsReadAsItIsAndAMissingOrEmptyOneIsNothing(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'guideline');
        file_put_contents($file, "We care.\n");

        self::assertSame('We care.', (new MarkdownGuidelineLoader())->load($file));
        file_put_contents($file, "  \n");
        self::assertNull((new MarkdownGuidelineLoader())->load($file));
        unlink($file);
        self::assertNull((new MarkdownGuidelineLoader())->load($file));
    }

    public function testTheShippedGuidelinesAreShort(): void
    {
        foreach (EmagmaMcpGuidelinesBundle::DEFAULTS as $name) {
            $content = (string) (new MarkdownGuidelineLoader())->load(\dirname(__DIR__, 3) . '/resources/guidelines/' . $name . '.md');

            self::assertStringStartsWith('# ', $content, $name);
            self::assertLessThan(600, mb_strlen($content), $name . ' stays short');
            self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $content, $name . ' has no long dash');
        }
    }
}
