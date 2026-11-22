<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Script;

/**
 * Reads the tokens of the scripts a model wrote, never runs them: which tools they name, and whether they hold
 * what a script must not: a construct the hardened process cannot bound (a keyword is not a function), a
 * function or a class outside the allow lists, a call whose target is computed, a class of its own.
 *
 * It refuses early and says why; for functions the process is the barrier. No class is disabled in the process
 * (`disable_classes` is gone from PHP 8.5): the inspector alone refuses a class outside the list.
 */
final class ScriptInspector
{
    /** Functions bootstrap.php needs and a script does not: reading stdin or writing stdout would break the dialogue. */
    private const array BOOTSTRAP_ONLY = ['fgets', 'fwrite', 'func_get_arg', 'basename'];

    /** The allowed functions that take a function, and the position of that argument: only a closure is accepted. */
    private const array CALLABLE_ARGUMENT = [
        'array_map' => 0, 'array_filter' => 1, 'usort' => 1, 'uasort' => 1, 'uksort' => 1,
        'array_find' => 1, 'array_any' => 1, 'array_all' => 1,
    ];

    /** @var array<string, true>|null */
    private ?array $functions = null;

    /** @var array<string, true>|null */
    private ?array $classes = null;

    /** Language constructs: loading other code, leaving, or writing on the dialogue's stdout. */
    private const array FORBIDDEN = [
        \T_EVAL => 'eval',
        \T_INCLUDE => 'include',
        \T_INCLUDE_ONCE => 'include_once',
        \T_REQUIRE => 'require',
        \T_REQUIRE_ONCE => 'require_once',
        \T_ECHO => 'echo',
        \T_PRINT => 'print',
        \T_INLINE_HTML => 'text outside <?php',
        \T_OPEN_TAG_WITH_ECHO => '<?=',
        \T_CLOSE_TAG => '?>',
        \T_EXIT => 'exit',
        \T_HALT_COMPILER => '__halt_compiler',
        \T_NAMESPACE => 'namespace',
        \T_GOTO => 'goto',
        \T_ATTRIBUTE => 'an attribute',
    ];

    private const array IGNORED = [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT];

    /**
     * @param list<string> $scripts
     *
     * @return list<string> the tools named as first argument of call(), sorted, each once
     */
    public function manifest(array $scripts): array
    {
        $tools = [];
        foreach ($scripts as $index => $script) {
            $tokens = $this->tokens($script, 'syntax_error', \sprintf('Step %d', $index));
            $this->assertNoForbiddenConstruct($tokens, $index);
            $this->assertOnlyAllowedCode($tokens, $index);

            foreach ($tokens as $position => $token) {
                if (!$this->isCall($tokens, $position)) {
                    continue;
                }
                $argument = $tokens[$position + 2] ?? null;
                $after = $tokens[$position + 3] ?? null;
                if (null === $argument || !$argument->is(\T_CONSTANT_ENCAPSED_STRING) || null === $after || !\in_array($after->text, [',', ')'], true)) {
                    throw new ScriptRefused('tool_not_literal', \sprintf('Step %d, line %d: the first argument of call() must be the name of the tool, as a plain string.', $index, $token->line));
                }
                $tools[stripcslashes(substr($argument->text, 1, -1))] = true;
            }
        }

        $names = array_map(strval(...), array_keys($tools));
        sort($names);

        return $names;
    }

    /**
     * The script with the tools named by call() renamed: only the literal that is call()'s first argument
     * changes, the rest of the code is kept to the byte.
     *
     * @param array<string, string> $names old name => new name
     */
    public function renameTools(string $script, array $names): string
    {
        $all = \PhpToken::tokenize($script);
        $significant = array_values(array_filter($all, static fn (\PhpToken $token): bool => !$token->is(self::IGNORED)));
        $literals = [];
        foreach ($significant as $position => $token) {
            $argument = $significant[$position + 2] ?? null;
            if ($this->isCall($significant, $position) && null !== $argument && $argument->is(\T_CONSTANT_ENCAPSED_STRING)) {
                $literals[spl_object_id($argument)] = true;
            }
        }

        $code = '';
        foreach ($all as $token) {
            $name = isset($literals[spl_object_id($token)]) ? stripcslashes(substr($token->text, 1, -1)) : null;
            $code .= null !== $name && isset($names[$name]) ? "'" . $names[$name] . "'" : $token->text;
        }

        return $code;
    }

    /**
     * The params are values, never code: `const NAME = literal;`, a literal being a string, a number, a
     * boolean, null or an array of literals.
     */
    public function assertParams(string $params): void
    {
        $tokens = $this->tokens($params, 'params_invalid', 'params.php');
        $count = \count($tokens);
        $position = 1;
        // declare(strict_types=1), which the server writes at the top of every file of a task.
        if (($tokens[1] ?? null)?->is(\T_DECLARE)) {
            $declare = implode('', array_map(static fn (\PhpToken $t): string => $t->text, \array_slice($tokens, 1, 7)));
            if (1 !== preg_match('/^declare\(strict_types=1\);$/i', $declare)) {
                throw new ScriptRefused('params_invalid', 'params.php: only declare(strict_types=1) is allowed.');
            }
            $position = 8;
        }
        while ($position < $count) {
            if (!$tokens[$position]->is(\T_CONST) ||
                !($tokens[$position + 1] ?? null)?->is(\T_STRING) ||
                '=' !== ($tokens[$position + 2] ?? null)?->text
            ) {
                throw new ScriptRefused('params_invalid', \sprintf('params.php, line %d: only `const NAME = value;` declarations are allowed.', $tokens[$position]->line));
            }
            $position += 3;
            $values = 0;
            while ($position < $count && ';' !== $tokens[$position]->text) {
                $token = $tokens[$position];
                $literal = $token->is([\T_CONSTANT_ENCAPSED_STRING, \T_LNUMBER, \T_DNUMBER, \T_DOUBLE_ARROW]) ||
                    \in_array($token->text, ['[', ']', ',', '-'], true) ||
                    ($token->is(\T_STRING) && \in_array(strtolower($token->text), ['true', 'false', 'null'], true));
                if (!$literal) {
                    throw new ScriptRefused('params_invalid', \sprintf('params.php, line %d: a value is a string, a number, a boolean, null or an array of those.', $token->line));
                }
                ++$values;
                ++$position;
            }
            if (0 === $values || $position >= $count) {
                throw new ScriptRefused('params_invalid', 'params.php: a declaration is incomplete.');
            }
            ++$position;
        }
    }

    /**
     * @return list<\PhpToken> without whitespace nor comments; the first one is the opening tag
     */
    private function tokens(string $code, string $reason, string $where): array
    {
        try {
            $all = \PhpToken::tokenize($code, \TOKEN_PARSE);
        } catch (\ParseError $error) {
            throw new ScriptRefused($reason, \sprintf('%s, line %d: %s.', $where, $error->getLine(), $error->getMessage()));
        }

        $tokens = array_values(array_filter($all, static fn (\PhpToken $token): bool => !$token->is(self::IGNORED)));
        if (!($tokens[0] ?? null)?->is(\T_OPEN_TAG)) {
            throw new ScriptRefused('params_invalid' === $reason ? $reason : 'construct_forbidden', \sprintf('%s: a script starts with <?php.', $where));
        }

        return $tokens;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function assertNoForbiddenConstruct(array $tokens, int $index): void
    {
        foreach ($tokens as $position => $token) {
            foreach (self::FORBIDDEN as $id => $name) {
                if ($token->is($id)) {
                    throw new ScriptRefused('construct_forbidden', \sprintf('Step %d, line %d: %s is not allowed in a script.', $index, $token->line, $name));
                }
            }
            if ($token->is(\T_FUNCTION) && 'call' === strtolower(($tokens[$position + 1] ?? null)->text ?? '')) {
                throw new ScriptRefused('construct_forbidden', \sprintf('Step %d, line %d: call() is provided, a script does not declare it.', $index, $token->line));
            }
        }
    }

    /**
     * The functions and classes of the allow lists, the target of every call written as it is.
     *
     * @param list<\PhpToken> $tokens
     */
    private function assertOnlyAllowedCode(array $tokens, int $index): void
    {
        $declared = [];
        foreach ($tokens as $position => $token) {
            if ($token->is(\T_FUNCTION) && ($tokens[$position + 1] ?? null)?->is(\T_STRING)) {
                $declared[strtolower($tokens[$position + 1]->text)] = true;
            }
        }

        $unqualified = [];
        foreach ($tokens as $position => $token) {
            $before = $tokens[$position - 1] ?? null;
            $next = $tokens[$position + 1] ?? null;
            $where = \sprintf('Step %d, line %d', $index, $token->line);

            // declare(strict_types=1) only: the server adds it anyway.
            if ($token->is(\T_DECLARE)) {
                $text = implode('', array_map(static fn (\PhpToken $t): string => $t->text, \array_slice($tokens, $position, 7)));
                if (1 !== preg_match('/^declare\(strict_types=1\);$/i', $text)) {
                    throw new ScriptRefused('construct_forbidden', $where . ': only declare(strict_types=1) is allowed.');
                }
            }
            if ('`' === $token->text) {
                throw new ScriptRefused('construct_forbidden', $where . ': backticks run a shell command.');
            }
            // `use` imports a name, except in `function () use ($x)`.
            if ($token->is(\T_USE) && ')' !== $before?->text) {
                throw new ScriptRefused('construct_forbidden', $where . ': use is not allowed in a script.');
            }

            if ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM]) && !$before?->is(\T_DOUBLE_COLON)) {
                throw new ScriptRefused('class_forbidden', $where . ': a script declares no class, interface, trait or enum.');
            }

            // A call, a property or a class whose name is computed: $f(), $$x, ${...}, $o->$m(), X::$m(), new $c.
            if ('$' === $token->text ||
                ($token->is(\T_VARIABLE) && '(' === $next?->text) ||
                ($token->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR]) && ($next?->is(\T_VARIABLE) || '{' === $next?->text)) ||
                ($token->is(\T_DOUBLE_COLON) && ($next?->is(\T_VARIABLE) || '{' === $next?->text)) ||
                ($token->is(\T_NEW) && ($next?->is(\T_VARIABLE) || '(' === $next?->text))
            ) {
                throw new ScriptRefused('dynamic_call', $where . ': the target of a call is written as it is, never computed; declare a named function to reuse code.');
            }

            // A class named: new X, X::..., instanceof X, catch (X $e).
            if ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED]) && (
                $before?->is([\T_NEW, \T_INSTANCEOF]) || \T_DOUBLE_COLON === $next?->id || $this->isCaughtType($tokens, $position)
            )) {
                $class = ltrim($token->text, '\\');
                if (!$this->classAllowed($class)) {
                    throw new ScriptRefused('class_not_allowed', \sprintf('%s: the class %s is not allowed in a script.', $where, $class));
                }
                if (!$token->is(\T_NAME_FULLY_QUALIFIED)) {
                    $unqualified[] = \sprintf('line %d: %s → \\%s', $token->line, $class, $class);
                }

                continue;
            }

            // A function called by its name.
            if ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED]) && '(' === $next?->text &&
                !$before?->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW, \T_CONST])
            ) {
                $function = strtolower(ltrim($token->text, '\\'));
                if ('call' !== $function && !isset($declared[$function]) && !isset($this->functions()[$function])) {
                    throw new ScriptRefused('function_not_allowed', \sprintf('%s: the function %s is not allowed in a script.', $where, $function));
                }
                // A function of PHP is written fully qualified; call() and the script's own functions need not be.
                if (!$token->is(\T_NAME_FULLY_QUALIFIED) && isset($this->functions()[$function])) {
                    $unqualified[] = \sprintf('line %d: %s() → \\%s()', $token->line, $token->text, $token->text);
                }
                if (isset(self::CALLABLE_ARGUMENT[$function])) {
                    $this->assertClosureArgument($tokens, $position + 1, self::CALLABLE_ARGUMENT[$function], $where, $function);
                }
            }
        }
        if ([] !== $unqualified) {
            throw new ScriptRefused('not_fully_qualified', \sprintf('Step %d: the functions and classes of PHP are written with a leading backslash: %s.', $index, implode('; ', $unqualified)));
        }
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function assertClosureArgument(array $tokens, int $open, int $argument, string $where, string $function): void
    {
        $depth = 0;
        $current = 0;
        $count = \count($tokens);
        for ($position = $open + 1; $position < $count; ++$position) {
            $text = $tokens[$position]->text;
            if (0 === $depth && $current === $argument) {
                $first = $tokens[$position];
                if ($first->is(\T_STATIC)) {
                    $first = $tokens[$position + 1] ?? $first;
                }
                if (!$first->is([\T_FUNCTION, \T_FN])) {
                    throw new ScriptRefused('callable_not_closure', \sprintf('%s: %s takes a closure (function () {} or fn () =>), never a name.', $where, $function));
                }

                return;
            }
            if (\in_array($text, ['(', '[', '{'], true)) {
                ++$depth;
            } elseif (\in_array($text, [')', ']', '}'], true)) {
                if (0 === $depth) {
                    return;
                }
                --$depth;
            } elseif (',' === $text && 0 === $depth) {
                ++$current;
            }
        }
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function isCaughtType(array $tokens, int $position): bool
    {
        // catch (A | B $e): walk back over the other types to the opening parenthesis.
        for ($back = $position - 1; $back >= 0; --$back) {
            $token = $tokens[$back];
            if ('(' === $token->text) {
                return ($tokens[$back - 1] ?? null)?->is(\T_CATCH) ?? false;
            }
            if ('|' !== $token->text && !$token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                return false;
            }
        }

        return false;
    }

    private function classAllowed(string $class): bool
    {
        if (isset($this->classes()[strtolower($class)])) {
            return true;
        }

        // What the engine throws: catching or throwing a Throwable is how a script handles a failure. Never
        // autoloaded: a name in a script must not load the server's code.
        return 0 === strcasecmp($class, \Throwable::class) ||
            ((class_exists($class, false) || interface_exists($class, false)) && is_subclass_of($class, \Throwable::class) && (new \ReflectionClass($class))->isInternal());
    }

    /**
     * @return array<string, true>
     */
    private function functions(): array
    {
        if (null === $this->functions) {
            /** @var list<string> $allowed */
            $allowed = require \dirname(__DIR__, 2) . '/resources/allowed-functions.php';
            $this->functions = array_fill_keys(array_diff(array_map(strtolower(...), $allowed), self::BOOTSTRAP_ONLY), true);
        }

        return $this->functions;
    }

    /**
     * @return array<string, true>
     */
    private function classes(): array
    {
        if (null === $this->classes) {
            /** @var list<string> $allowed */
            $allowed = require \dirname(__DIR__, 2) . '/resources/allowed-classes.php';
            $this->classes = array_fill_keys(array_map(strtolower(...), $allowed), true);
        }

        return $this->classes;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function isCall(array $tokens, int $position): bool
    {
        $token = $tokens[$position];
        if (!$token->is([\T_STRING, \T_NAME_FULLY_QUALIFIED]) || 'call' !== strtolower(ltrim($token->text, '\\'))) {
            return false;
        }
        if ('(' !== ($tokens[$position + 1] ?? null)?->text) {
            return false;
        }
        $before = $tokens[$position - 1] ?? null;

        // A method or a declaration named call is not the provided function.
        return null === $before || !$before->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION, \T_NEW]);
    }
}
