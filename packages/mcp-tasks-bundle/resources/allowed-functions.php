<?php

declare(strict_types=1);

// What a script of a task may call, and nothing else: no file, network, process, database or
// environment function. Every other internal function of the binary is disabled (build-ini.php).
return [
    // strings
    'strlen', 'trim', 'ltrim', 'rtrim', 'sprintf', 'str_contains', 'str_starts_with', 'str_ends_with',
    'str_replace', 'str_pad', 'str_repeat', 'substr', 'strpos', 'strrpos', 'strtolower', 'strtoupper', 'ucfirst',
    'lcfirst', 'ucwords', 'implode', 'explode', 'strip_tags', 'number_format', 'strcmp', 'strcasecmp', 'strval',
    'mb_strlen', 'mb_substr', 'mb_strtolower', 'mb_strtoupper', 'mb_strpos', 'mb_str_pad', 'html_entity_decode',
    'htmlspecialchars',
    // regular expressions
    'preg_match', 'preg_match_all', 'preg_replace', 'preg_split', 'preg_quote',
    // arrays
    'count', 'in_array', 'array_key_exists', 'array_keys', 'array_values', 'array_map', 'array_filter',
    'array_merge', 'array_column', 'array_unique', 'array_slice', 'array_chunk', 'array_fill_keys', 'array_flip',
    'array_search', 'array_sum', 'array_diff', 'array_diff_key', 'array_intersect', 'array_intersect_key',
    'array_combine', 'array_reverse', 'array_key_first', 'array_key_last', 'array_is_list', 'array_push',
    'array_pop', 'array_shift', 'array_find', 'array_any', 'array_all', 'sort', 'rsort', 'usort', 'ksort', 'krsort',
    'asort', 'arsort', 'uasort', 'uksort', 'min', 'max', 'range',
    // numbers
    'abs', 'round', 'floor', 'ceil', 'intdiv', 'intval', 'floatval', 'fmod',
    // types
    'is_int', 'is_float', 'is_string', 'is_bool', 'is_array', 'is_numeric', 'is_null', 'is_callable',
    // json
    'json_encode', 'json_decode',
    // dates
    'time', 'date', 'strtotime',
    // bootstrap.php
    'fgets', 'fwrite', 'basename', 'func_get_arg',
];
