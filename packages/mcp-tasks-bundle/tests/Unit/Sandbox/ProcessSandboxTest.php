<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tests\Unit\Sandbox;

use Emagma\McpTasksBundle\Sandbox\HardenedIni;
use Emagma\McpTasksBundle\Sandbox\ProcessSandbox;
use Emagma\McpTasksBundle\Sandbox\SandboxSessionInterface;
use Emagma\McpTasksBundle\Sandbox\SandboxTimeout;
use Emagma\McpTasksBundle\Script\TaskFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Plays real scripts in the real hardened process.
 */
#[CoversClass(ProcessSandbox::class)]
#[CoversClass(HardenedIni::class)]
final class ProcessSandboxTest extends TestCase
{
    private string $directory;

    private ?SandboxSessionInterface $session = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/mcp-task-' . bin2hex(random_bytes(6));
        (new Filesystem())->mkdir($this->directory . '/steps/000000');
        file_put_contents($this->directory . '/params.php', "<?php\nconst LOCALE = 'fr_FR';\n");
        putenv('MCP_TASK_TEST_SECRET=s3cret');
    }

    protected function tearDown(): void
    {
        $this->session?->close();
        (new Filesystem())->remove([$this->directory, $this->directory . '.ini']);
        putenv('MCP_TASK_TEST_SECRET');
    }

    public function testAScriptAsksForAToolAndGetsItsResult(): void
    {
        $session = $this->play("<?php\n\$found = call('get_products', ['codes' => ['A'], 'locale' => LOCALE]);\ncall('note', ['name' => \$found['name']]);");

        self::assertSame(['call' => 'get_products', 'args' => ['codes' => ['A'], 'locale' => 'fr_FR'], 'id' => 1], $session->receive());
        $session->send(['id' => 1, 'result' => ['name' => 'Apollo']]);
        self::assertSame(['call' => 'note', 'args' => ['name' => 'Apollo'], 'id' => 2], $session->receive());
        $session->send(['id' => 2, 'result' => []]);
        self::assertSame(['ok' => true, 'seal' => 's3al'], $session->receive());
        self::assertNull($session->receive());
    }

    public function testAFailedCallIsAnExceptionInTheScript(): void
    {
        $session = $this->play("<?php\ntry { call('a', []); } catch (\\RuntimeException \$e) { call('caught', ['message' => \$e->getMessage()]); }\ncall('b', []);");

        $session->receive();
        $session->send(['id' => 1, 'error' => 'not in the manifest']);
        self::assertSame(['message' => 'a: not in the manifest'], $session->receive()['args'] ?? null);
        $session->send(['id' => 2, 'result' => []]);
        $session->receive();
        $session->send(['id' => 3, 'result' => ['ok' => false, 'reason' => 'validation_error']]);
        self::assertSame(['ok' => false, 'error' => 'b: validation_error', 'seal' => 's3al'], $session->receive());
    }

    public function testAnUncaughtExceptionEndsTheStepWithItsMessage(): void
    {
        $session = $this->play("<?php\nthrow new \\RuntimeException('Product X not found.');");

        self::assertSame(['ok' => false, 'error' => 'Product X not found.', 'seal' => 's3al'], $session->receive());
    }

    /**
     * What a script must not reach: files, the network, processes, the worker's environment.
     */
    public function testTheScriptHasNothingButTheDialogue(): void
    {
        $attempts = [
            'file' => "file_get_contents('/etc/passwd')",
            'write' => "file_put_contents('x', 'y')",
            'shell' => "shell_exec('id')",
            'curl' => 'curl_init()',
            'env' => "getenv('MCP_TASK_TEST_SECRET')",
            'ini' => "ini_set('open_basedir', '/')",
            'socket' => "fsockopen('localhost', 80)",
            // No class is disabled in the process (the inspector refuses them): Reflection cannot reach a
            // disabled function either.
            'reflection' => "(new \\ReflectionFunction('shell_exec'))->invoke('id')",
            // Classes stay usable in the process: open_basedir bounds what they reach.
            'pdo mysql' => "new \\PDO('mysql:host=database;dbname=mcp', 'x', 'y')",
            'pdo sqlite outside the task' => "new \\PDO('sqlite:/tmp/outside.sqlite')",
            'spl file outside the task' => "(new \\SplFileObject('/etc/passwd'))->fgets()",
        ];
        $script = "<?php\n\$out = [];\n";
        foreach ($attempts as $name => $code) {
            $script .= "try { \$out['{$name}'] = {$code}; } catch (\\Throwable \$e) { \$out['{$name}'] = 'refused'; }\n";
        }
        $script .= "\$out['superglobals'] = count(\$_ENV) + count(array_filter(array_keys(\$_SERVER), fn (\$k) => str_contains((string) \$k, 'SECRET')));\n";
        $script .= "call('report', \$out);";

        $session = $this->play($script);
        $report = $session->receive()['args'] ?? [];

        self::assertIsArray($report);
        foreach (array_keys($attempts) as $name) {
            self::assertSame('refused', $report[$name] ?? null, $name);
        }
        self::assertSame(0, $report['superglobals']);
    }

    public function testAStepRunsInStrictMode(): void
    {
        // TaskFiles writes every step in strict mode: a value of the wrong type stops it.
        $session = $this->play(TaskFiles::strict("<?php\n\$n = strlen(12);\ncall('never', []);"));

        $end = $session->receive();
        self::assertFalse($end['ok'] ?? null);
        self::assertStringContainsString('must be of type string', (string) ($end['error'] ?? ''));
    }

    public function testAStepCannotLoadAFileOutsideTheTask(): void
    {
        // The inspector refuses `require` in a script; the process refuses it anyway.
        $session = $this->play("<?php\n\$x = @require '/etc/hostname';\ncall('loaded', []);");

        self::assertFalse($session->receive()['ok'] ?? null);
    }

    public function testASessionThatLastsTooLongIsStopped(): void
    {
        $session = $this->play("<?php\ncall('wait', []);\n", 0.5);
        $session->receive();

        $this->expectException(SandboxTimeout::class);
        $session->receive();
    }

    public function testAProcessThatDiesLeavesNoLine(): void
    {
        $session = $this->play("<?php\n\$a = str_repeat('x', 500 * 1024 * 1024);");

        self::assertNull($session->receive());
        self::assertStringContainsString('memory', $session->errorOutput());
    }

    private function play(string $script, float $timeout = 10.0): SandboxSessionInterface
    {
        file_put_contents($this->directory . '/steps/000000/run.php', $script);
        $this->session = (new ProcessSandbox())->open($this->directory, $timeout);
        $this->session->send(['play' => '000000', 'seal' => 's3al']);

        return $this->session;
    }
}
