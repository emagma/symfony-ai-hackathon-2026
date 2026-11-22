<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Script;

use Emagma\McpTasksBundle\Script\ScriptInspector;
use Emagma\McpTasksBundle\Script\ScriptRefused;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ScriptInspector::class)]
#[CoversClass(ScriptRefused::class)]
final class ScriptInspectorTest extends TestCase
{
    public function testTheManifestIsTheToolsNamedInTheScripts(): void
    {
        $manifest = (new ScriptInspector())->manifest([
            "<?php\n\$list = call('search_products', ['page' => 1]);\ncall(\"upsert_products\", []);",
            "<?php\nfunction f(): void { \\call('search_products', []); }\ncall('delete_taxons', []);",
        ]);

        self::assertSame(['delete_taxons', 'search_products', 'upsert_products'], $manifest);
    }

    public function testACommentOrAStringNamingCallIsNotACall(): void
    {
        $manifest = (new ScriptInspector())->manifest([
            "<?php\n// call('delete_products', [])\n\$text = \"call('delete_products')\";\n\$object->call(\$x);\ncall('get_products', []);",
        ]);

        self::assertSame(['get_products'], $manifest);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedScripts(): iterable
    {
        yield 'a tool named by a variable' => ["<?php\ncall(\$tool, []);", 'tool_not_literal'];
        yield 'a tool named by a concatenation' => ["<?php\ncall('delete_' . \$what, []);", 'tool_not_literal'];
        yield 'an interpolated tool name' => ["<?php\ncall(\"delete_\$what\", []);", 'tool_not_literal'];
        yield 'eval' => ["<?php\neval('call(\"x\", []);');", 'construct_forbidden'];
        yield 'include' => ["<?php\ninclude 'other.php';", 'construct_forbidden'];
        yield 'require_once' => ["<?php\nrequire_once 'other.php';", 'construct_forbidden'];
        yield 'echo, which would corrupt the dialogue' => ["<?php\necho 'hello';", 'construct_forbidden'];
        yield 'text outside php' => ["hello <?php\ncall('x', []);", 'construct_forbidden'];
        yield 'exit' => ["<?php\nexit(0);", 'construct_forbidden'];
        yield 'a redefinition of call' => ["<?php\nfunction call(string \$t, array \$a): array { return []; }", 'construct_forbidden'];
        yield 'invalid php' => ["<?php\nif (", 'syntax_error'];
        yield 'no opening tag' => ["call('x', []);", 'construct_forbidden'];
        yield 'a function outside the list' => ["<?php\n\$x = file_get_contents('/etc/passwd');", 'function_not_allowed'];
        yield 'a fully qualified function outside the list' => ["<?php\n\\shell_exec('id');", 'function_not_allowed'];
        yield 'a function bootstrap.php keeps for itself' => ["<?php\nfwrite(STDOUT, 'x');", 'function_not_allowed'];
        yield 'a function called by a variable' => ["<?php\n\$f = 'rmdir';\n\$f('/srv');", 'dynamic_call'];
        yield 'a variable variable' => ["<?php\n\$info = 'rmdir';\n\$x = \$\$info;", 'dynamic_call'];
        yield 'a computed variable' => ["<?php\n\$x = \${'na' . 'me'};", 'dynamic_call'];
        yield 'a method named by a variable' => ["<?php\n\$d = new DateTimeImmutable();\n\$d->\$m();", 'dynamic_call'];
        yield 'a class named by a variable' => ["<?php\n\$c = 'SplFileObject';\n\$o = new \$c('/etc/passwd');", 'dynamic_call'];
        yield 'a function given by its name' => ["<?php\n\$x = array_map('rmdir', ['/srv']);", 'callable_not_closure'];
        yield 'a method given as an array' => ["<?php\nusort(\$a, [\$o, 'compare']);", 'callable_not_closure'];
        yield 'a class outside the list' => ["<?php\n\$f = new SplFileObject('/etc/passwd');", 'class_not_allowed'];
        yield 'XMLReader, that PHP 8.4.27 cannot disable' => ["<?php\n\$r = new \\XMLReader();", 'class_not_allowed'];
        yield 'reflection' => ["<?php\n\$r = new \\ReflectionFunction('call');", 'class_not_allowed'];
        yield 'a static call on a class outside the list' => ["<?php\n\$p = Phar::running();", 'class_not_allowed'];
        yield 'a class of its own' => ["<?php\nclass Helper {}", 'class_forbidden'];
        yield 'an anonymous class' => ["<?php\n\$o = new class {};", 'class_forbidden'];
        yield 'an enum' => ["<?php\nenum Mode { case A; }", 'class_forbidden'];
        yield 'a namespace' => ["<?php\nnamespace App;", 'construct_forbidden'];
        yield 'an import' => ["<?php\nuse Some\\Thing;", 'construct_forbidden'];
        yield 'another declare' => ["<?php\ndeclare(ticks=1);", 'construct_forbidden'];
        yield 'backticks' => ["<?php\n\$x = `id`;", 'construct_forbidden'];
    }

    public function testEveryFunctionAndClassOfPhpWrittenWithoutBackslashIsListedAtOnce(): void
    {
        $script = "<?php\nfunction label(): string { return 'x'; }\n\$a = sprintf('%s', label());\n\$d = new DateTimeImmutable();\ncall('search_products', []);\n\$n = \\count([]);";

        try {
            (new ScriptInspector())->manifest([$script]);
            self::fail('The script should have been refused.');
        } catch (ScriptRefused $refused) {
            self::assertSame('not_fully_qualified', $refused->reason);
            self::assertStringContainsString('line 3: sprintf() → \\sprintf()', $refused->getMessage());
            self::assertStringContainsString('line 4: DateTimeImmutable → \\DateTimeImmutable', $refused->getMessage());
            self::assertStringNotContainsString('label', $refused->getMessage(), 'a function of the script');
            self::assertStringNotContainsString('call(', $refused->getMessage(), 'the provided call()');
        }
    }

    public function testWhatAScriptNeedsStaysAllowed(): void
    {
        $script = <<<'PHP'
            <?php

            declare(strict_types=1);

            function label(array $product): string
            {
                return \sprintf('%s (%s)', \trim((string) ($product['name'] ?? '')), $product['code']);
            }

            $found = call('search_products', ['page' => 1]);
            $labels = \array_map(fn (array $p): string => label($p), $found['items']);
            \usort($labels, static function (string $a, string $b): int { return \strcmp($a, $b); });
            $missing = \array_filter($found['items'], function (array $p) use ($labels): bool { return [] === $labels; });
            $since = new \DateTimeImmutable('-1 day');
            try {
                call('get_products', ['codes' => [$found['items'][0]['code']], 'since' => $since->format(DATE_ATOM)]);
            } catch (\RuntimeException | \JsonException $e) {
                throw new \LogicException('Cannot read: ' . $e->getMessage(), 0, $e);
            }
            $class = $e::class;
            PHP;

        self::assertSame(['get_products', 'search_products'], (new ScriptInspector())->manifest([$script]));
    }

    #[DataProvider('refusedScripts')]
    public function testAScriptIsRefused(string $script, string $reason): void
    {
        try {
            (new ScriptInspector())->manifest([$script]);
            self::fail('The script should have been refused.');
        } catch (ScriptRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }

    public function testParamsOnlyHoldConstantsOfLiterals(): void
    {
        (new ScriptInspector())->assertParams(<<<'PHP'
            <?php
            // decided with the user
            const LOCALE = 'fr_FR';
            const MAX = -3;
            const RATE = 1.5;
            const ON = true;
            const NOTHING = null;
            const CHILDREN = ['a' => 'A', 'b' => ['c', 2]];
            PHP);

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedParams(): iterable
    {
        yield 'a call' => ["<?php\nconst A = strtoupper('a');"];
        yield 'a variable' => ["<?php\n\$a = 1;"];
        yield 'a statement' => ["<?php\nconst A = 1;\ncall('x', []);"];
        yield 'another constant' => ["<?php\nconst A = PHP_OS;"];
        yield 'a concatenation' => ["<?php\nconst A = 'a' . 'b';"];
        yield 'invalid php' => ["<?php\nconst A = ;"];
    }

    #[DataProvider('refusedParams')]
    public function testParamsAreRefused(string $params): void
    {
        try {
            (new ScriptInspector())->assertParams($params);
            self::fail('The params should have been refused.');
        } catch (ScriptRefused $refused) {
            self::assertSame('params_invalid', $refused->reason);
        }
    }
}
