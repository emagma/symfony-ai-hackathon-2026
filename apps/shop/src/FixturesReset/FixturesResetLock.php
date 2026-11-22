<?php

declare(strict_types=1);

namespace App\FixturesReset;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One reset at a time. A plain flock(): one shop container serves the admin
 * and runs `app:fixtures:reset` (by hand, `docker compose exec shop`), so a
 * file under var/ is enough. The lock dies with the process that holds it.
 */
final class FixturesResetLock
{
    /** @var resource|null */
    private $handle;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/fixtures-reset.lock')]
        private readonly string $path,
    ) {
    }

    public function acquire(): bool
    {
        if (null !== $this->handle) {
            return true;
        }

        $handle = $this->open();
        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if (null === $this->handle) {
            return;
        }

        flock($this->handle, \LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    /**
     * Whether another process holds the lock right now.
     */
    public function isHeldElsewhere(): bool
    {
        if (null !== $this->handle) {
            return false;
        }

        $handle = $this->open();
        $free = flock($handle, \LOCK_EX | \LOCK_NB);
        if ($free) {
            flock($handle, \LOCK_UN);
        }
        fclose($handle);

        return !$free;
    }

    /**
     * @return resource
     */
    private function open()
    {
        $directory = \dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Cannot create the directory of the lock file "%s".', $this->path));
        }

        $handle = @fopen($this->path, 'c');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Cannot open the lock file "%s".', $this->path));
        }

        return $handle;
    }
}
