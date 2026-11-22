<?php

declare(strict_types=1);

// PHP only has blacklists: the whitelist is the internal functions and classes of THIS binary, minus the
// allowed ones. Run with the binary and the flags of the hardened process (`php -n`), so that what is
// compiled in statically (curl, PDO, Phar…) is disabled too. Usage: php -n build-ini.php <ini> <memory_limit>
// The directory a script may read (open_basedir) is the task's own: given on the command line of each process.
[, $target, $memoryLimit] = $argv;

$allowed = require __DIR__ . '/allowed-functions.php';

// exit and die are functions since PHP 8.4, and cannot be disabled: the inspector refuses them in a script.
$functions = array_diff(get_defined_functions()['internal'], $allowed, ['exit', 'die']);

// No disable_classes: it is gone from PHP 8.5 and crashed 8.4.27 on XMLReader. The classes a script may use are
// the inspector's to check (allowed-classes.php), when the task is created.
$lines = ['disable_functions=' . implode(',', $functions)];

$written = file_put_contents($target . '.tmp', implode("\n", [
    ...$lines,
    // An exception must not carry the arguments of the frames above the script: the seal is one.
    'zend.exception_ignore_args=1',
    'allow_url_fopen=0',
    'allow_url_include=0',
    'memory_limit=' . $memoryLimit,
    'max_execution_time=0',
    'display_errors=stderr',
    'log_errors=0',
    'html_errors=0',
    'error_reporting=E_ALL',
    '',
]));
// Written aside then moved: two workers building it at once never read half a file.
if (false === $written || !rename($target . '.tmp', $target)) {
    fwrite(STDERR, "Cannot write {$target}\n");
    exit(1);
}
