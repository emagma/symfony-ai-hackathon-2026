<?php

declare(strict_types=1);

// Castor keeps running from the directory it was started in (the tasks already give their own paths);
// Castor 2.0 changes to the context's working directory by default.
defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', false);

use Castor\Attribute\AsContext;
use Castor\Context;
use function Castor\import;
use function Castor\load_dot_env;

#[AsContext(default: true, name: 'default')]
function default_context(): Context
{
    $envFile = __DIR__ . '/docker/.env';
    $data = is_file($envFile) ? load_dot_env($envFile) : [];

    return new Context($data + [
        'RUNTIME' => Runtime::detect()->value,
    ]);
}

import(__DIR__ . '/.castor');
