<?php

declare(strict_types=1);

/**
 * The quality gates of the whole monorepo - the same ones CI runs, one job per
 * application (bin/ci/*.sh).
 */

namespace quality;

use Castor\Attribute\AsTask;

use function Castor\io;
use function init\ok;

#[AsTask(name: 'all', description: 'ECS + PHPStan + PHPUnit of every application and package - the same gates as CI')]
function all(): void
{
    \guard_host();

    io()->title('plugins (core and events)');
    \plugin\quality();

    io()->title('MCP server');
    \mcp\quality();

    io()->title('Chat');
    \chat\quality();

    io()->title('Shop');
    \shop\quality();

    ok('every quality gate is green');
}
