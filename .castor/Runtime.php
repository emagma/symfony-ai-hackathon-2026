<?php

declare(strict_types=1);

// Enums imported by Castor MUST live at root namespace.

enum Runtime: string
{
    case Host = 'host';
    case Container = 'container';
    case CI = 'ci';

    public static function detect(): self
    {
        if (false !== getenv('DOCKER_CONTAINER')) {
            return self::Container;
        }

        if (false !== getenv('CI')) {
            return self::CI;
        }

        return self::Host;
    }
}

/**
 * Tasks that drive `docker compose` from the outside only make sense on the host.
 *
 * A runtime guard rather than `#[AsTask(enabled: ...)]`: an `enabled` expression
 * that evaluates false hides the task from `castor list` altogether, which is a
 * poor trade for a project whose task list IS its documentation.
 */
function guard_host(string $what = 'This task'): void
{
    if (Runtime::Host->value !== \Castor\variable('RUNTIME')) {
        throw new \RuntimeException(
            $what . ' drives docker compose and must run on the host, not inside a container.',
        );
    }
}
