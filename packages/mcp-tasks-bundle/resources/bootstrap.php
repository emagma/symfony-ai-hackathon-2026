<?php

declare(strict_types=1);

// The only code of a task the model did not write. Started in the directory of the task by the worker,
// with the hardened ini: it has stdin and stdout, nothing else.

function call(string $tool, array $args = []): array
{
    static $id = 0;

    fwrite(\STDOUT, json_encode(['call' => $tool, 'args' => $args, 'id' => ++$id]) . "\n");
    $reply = json_decode((string) fgets(\STDIN), true);
    if (!is_array($reply)) {
        throw new \RuntimeException($tool . ': the worker did not answer.');
    }
    if (isset($reply['error'])) {
        throw new \RuntimeException($tool . ': ' . $reply['error']);
    }
    $result = is_array($reply['result'] ?? null) ? $reply['result'] : [];
    if (false === ($result['ok'] ?? true)) {
        throw new \RuntimeException($tool . ': ' . (is_string($result['reason'] ?? null) ? $result['reason'] : 'failure'));
    }

    return $result;
}

// In a function, so that nothing here is a global a script could read: the seal the worker sent proves
// that the last line of the dialogue is this file's, not one a script wrote on stdout by itself.
(static function (): void {
    $request = json_decode((string) fgets(\STDIN), true);
    $request = is_array($request) ? $request : [];
    $seal = is_string($request['seal'] ?? null) ? $request['seal'] : '';
    $step = 'steps/' . basename(is_string($request['play'] ?? null) ? $request['play'] : '') . '/run.php';
    unset($request);

    try {
        require 'params.php';
        // The script gets a scope of its own, with nothing in it.
        (static function (): void {
            require func_get_arg(0);
        })($step);
        $line = ['ok' => true];
    } catch (\Throwable $error) {
        $line = ['ok' => false, 'error' => $error->getMessage()];
    }
    fwrite(\STDOUT, json_encode($line + ['seal' => $seal], \JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
})();
