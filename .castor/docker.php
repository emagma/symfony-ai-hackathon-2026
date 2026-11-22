<?php

declare(strict_types=1);

namespace docker;

use Castor\Attribute\AsTask;
use function Castor\run;
use function init\compose;
use function init\long;

#[AsTask(description: 'Build the PHP image shared by the three apps')]
function build(): void
{
    \guard_host();

    run(compose('build', 'shop'), context: long());
}

#[AsTask(description: 'Start the stack')]
function up(): void
{
    \guard_host();

    run(compose('up', '-d', '--wait', '--wait-timeout', '240'), context: long());
}

#[AsTask(description: 'Stop the stack')]
function down(): void
{
    \guard_host();

    run(compose('down'), context: long());
}

#[AsTask(description: 'Tail logs (all services or one)')]
function logs(string $service = ''): void
{
    \guard_host();

    run(compose('logs', '-f', ...('' === $service ? [] : [$service])), context: long());
}
