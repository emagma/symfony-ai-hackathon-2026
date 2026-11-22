<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\FunctionNotation\NativeFunctionInvocationFixer;
use PhpCsFixer\Fixer\Import\FullyQualifiedStrictTypesFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

// What emagma_mcp_tasks.linter fixes in the scripts of a task before they are inspected and read: the functions and the
// classes of PHP written with a leading backslash (\sprintf(), new \RuntimeException()).
return ECSConfig::configure()
    ->withConfiguredRule(NativeFunctionInvocationFixer::class, ['include' => ['@all'], 'scope' => 'all', 'strict' => true])
    ->withConfiguredRule(FullyQualifiedStrictTypesFixer::class, ['leading_backslash_in_global_namespace' => true, 'import_symbols' => false]);
