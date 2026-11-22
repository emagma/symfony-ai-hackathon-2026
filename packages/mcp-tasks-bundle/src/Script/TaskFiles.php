<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Script;

use Symfony\Component\Filesystem\Filesystem;

/**
 * The scripts of a task on disk: `<dir>/<id>/params.php` and one `steps/<number on six digits>/run.php`
 * per step, sorted in the order they are played.
 */
final readonly class TaskFiles
{
    public function __construct(
        private string $directory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public static function step(int $index): string
    {
        return \sprintf('%06d', $index);
    }

    public function directory(string $taskId): string
    {
        // An id is a UUID the server generated; never a path.
        return rtrim($this->directory, '/') . '/' . preg_replace('/[^a-zA-Z0-9-]/', '', $taskId);
    }

    /**
     * @param list<string> $scripts
     */
    public function write(string $taskId, string $params, array $scripts): void
    {
        $directory = $this->directory($taskId);
        $this->filesystem->remove($directory);
        $this->filesystem->dumpFile($directory . '/params.php', self::strict($params));
        foreach ($scripts as $index => $script) {
            $this->filesystem->dumpFile($directory . '/steps/' . self::step($index) . '/run.php', self::strict($script));
        }
    }

    /**
     * Every file of a task runs in strict mode: a value of the wrong type stops the step instead of being
     * converted silently. The line is added when the model did not write it.
     */
    public static function strict(string $code): string
    {
        if (1 === preg_match('/^<\?php\s+declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/i', $code)) {
            return $code;
        }

        return (string) preg_replace('/^<\?php\b\s*/', "<?php\n\ndeclare(strict_types=1);\n\n", $code, 1);
    }

    public function params(string $taskId): string
    {
        return $this->read($this->directory($taskId) . '/params.php');
    }

    public function script(string $taskId, int $index): string
    {
        return $this->read($this->directory($taskId) . '/steps/' . self::step($index) . '/run.php');
    }

    public function delete(string $taskId): void
    {
        $directory = $this->directory($taskId);
        $this->filesystem->remove($directory);
    }

    private function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
