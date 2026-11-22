<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Testing\Conformance;

use Emagma\McpSyliusBundle\ToolSources;
use PHPUnit\Framework\TestCase;

/**
 * No tool writes a path or an IRI prefix of its own: routes are what lets one server talk to Sylius 1.14 and 2.x.
 */
abstract class ApiPathConventionsTestCase extends TestCase
{
    abstract protected static function tools(): ToolSources;

    protected static function minimumChecked(): int
    {
        return 1;
    }

    private const string HAND_BUILT_PATH = <<<'REGEX'
        #/api/v2/admin/|rawurlencode\(|->(?:getDecoded|getRaw|getDecodedMany|put|post|patch|delete|deleteMany|postMultipart|putMany|postMany)\(\s*'|run(?:Upsert|Delete)\(\s*'[^']*',\s*'#
        REGEX;

    public function testNoToolBuildsAnAdminApiPathByHand(): void
    {
        $offenders = [];
        foreach (static::tools()->files('*.php') as $relative => $file) {
            if (1 === preg_match(self::HAND_BUILT_PATH, (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'build the path with Route and Resource (src/AdminApi/Adapter)');
    }
}
