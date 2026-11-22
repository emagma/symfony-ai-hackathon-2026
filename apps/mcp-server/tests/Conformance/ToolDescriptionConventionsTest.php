<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Conformance;

use Emagma\McpCoreBundle\Authorization\Deprecated;
use Emagma\SyliusMcpServer\ServerSources;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use PHPUnit\Framework\TestCase;

/** The shared protocol is stated once in the server's `instructions`: a copy in a description rides with every LLM call. */
final class ToolDescriptionConventionsTest extends TestCase
{
    private const array SHARED_PROTOCOL_PHRASES = [
        'Confirmation is REQUIRED',
        're-invoke with confirm',
        'Re-invoke with confirm',
        'Pagination: page',
        'capped at 50',
        'default from env',
        'defaults to env locale',
        'elicitation_unavailable_preview_only',
        'human_confirmation_required',
        'cancelled, declined',
        ', reason} otherwise',
        'will cover',
        'v1 limitation',
        'not yet',
        'NOT yet',
        'future',
        'TODO',
    ];

    /** Reason codes that look like a tool name. */
    private const array NOT_TOOL_NAMES = [
        'delete_failed',
        'revert_incomplete',
    ];

    private const int MAX_LENGTH = 1000;

    private const array LONG_BY_DESIGN = [
        'search_orders', // 20+ server-side filters, each with its own semantics
        'search_customers', // order-value filters whose scoping rules are not obvious
    ];

    public function testNoDescriptionRepeatsTheSharedProtocol(): void
    {
        $offenders = [];
        foreach ($this->descriptions() as $name => $description) {
            foreach (self::SHARED_PROTOCOL_PHRASES as $phrase) {
                if (str_contains($description, $phrase)) {
                    $offenders[] = $name . ': "' . $phrase . '"';
                }
            }
        }

        self::assertSame([], $offenders, "These descriptions repeat what instructions state once:\n" . implode("\n", $offenders));
    }

    public function testDescriptionsStayShortUnlessListedAsLongByDesign(): void
    {
        $tooLong = [];
        foreach ($this->descriptions() as $name => $description) {
            $length = \strlen($description);
            if ($length > self::MAX_LENGTH && !\in_array($name, self::LONG_BY_DESIGN, true)) {
                $tooLong[] = \sprintf('%s (%d chars)', $name, $length);
            }
        }

        self::assertSame([], $tooLong, 'Over ' . self::MAX_LENGTH . " characters without being listed as long by design:\n" . implode("\n", $tooLong));
    }

    public function testEveryToolNameCitedInADescriptionExists(): void
    {
        $descriptions = $this->descriptions();
        $verbs = array_unique(array_map(static fn (string $name): string => explode('_', $name, 2)[0], array_keys($descriptions)));
        $pattern = '/\\b(?:' . implode('|', $verbs) . ')_[a-z][a-z_]*\\b/';

        $dangling = [];
        foreach ($descriptions + $this->parameterDescriptions() + $this->hintLiterals() as $name => $description) {
            preg_match_all($pattern, $description, $matches);
            foreach (array_unique($matches[0]) as $cited) {
                if (!isset($descriptions[$cited]) && !\in_array($cited, self::NOT_TOOL_NAMES, true)) {
                    $dangling[] = $name . ' cites ' . $cited;
                }
            }
        }

        self::assertSame([], $dangling, "Tool names cited in descriptions that are not tools:\n" . implode("\n", $dangling));
    }

    public function testTheLongByDesignListOnlyNamesToolsThatNeedIt(): void
    {
        $descriptions = $this->descriptions();
        foreach (self::LONG_BY_DESIGN as $name) {
            self::assertArrayHasKey($name, $descriptions, $name . ' is not a tool anymore');
            self::assertGreaterThan(self::MAX_LENGTH, \strlen($descriptions[$name]), $name . ' fits the limit: drop it from the list');
        }
    }

    /**
     * @return array<string, string> tool name => description
     */
    /**
     * @return array<string, string>
     */
    private function parameterDescriptions(): array
    {
        $out = [];
        foreach ($this->toolClasses() as $name => $reflection) {
            if (!$reflection->hasMethod('__invoke')) {
                continue;
            }
            foreach ($reflection->getMethod('__invoke')->getParameters() as $parameter) {
                foreach ($parameter->getAttributes(Schema::class) as $attribute) {
                    $schema = $attribute->newInstance();
                    $key = $name . '.' . $parameter->getName();
                    if (null !== $schema->description) {
                        $out[$key] = $schema->description;
                    }
                    foreach (self::nestedDescriptions($schema->definition ?? []) as $path => $description) {
                        $out[$key . '.' . $path] = $description;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function hintLiterals(): array
    {
        $out = [];
        foreach (ServerSources::tools()->files('*.php') as $relative => $file) {
            preg_match_all("/'hint' => '((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[1] as $i => $hint) {
                $out[$relative . ' hint#' . $i] = $hint;
            }
        }

        return $out;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return array<string, string>
     */
    private static function nestedDescriptions(array $schema, string $path = ''): array
    {
        $out = [];
        foreach ($schema as $key => $value) {
            $here = '' === $path ? (string) $key : $path . '.' . $key;
            if ('description' === $key && \is_string($value)) {
                $out[$here] = $value;
            } elseif (\is_array($value)) {
                $out += self::nestedDescriptions($value, $here);
            }
        }

        return $out;
    }

    private function descriptions(): array
    {
        $descriptions = [];
        foreach ($this->toolClasses() as $name => $reflection) {
            foreach ($reflection->getAttributes(McpTool::class) as $attribute) {
                $descriptions[$name] = (string) $attribute->newInstance()->description;
            }
        }

        self::assertGreaterThan(100, \count($descriptions), 'tool discovery looks broken');

        return $descriptions;
    }

    /**
     * @return array<string, \ReflectionClass<object>>
     */
    private function toolClasses(): array
    {
        $classes = [];

        foreach (ServerSources::tools()->classes() as $class) {
            $reflection = new \ReflectionClass($class);
            if ([] !== $reflection->getAttributes(Deprecated::class)) {
                continue;
            }
            foreach ($reflection->getAttributes(McpTool::class) as $attribute) {
                $classes[(string) $attribute->newInstance()->name] = $reflection;
            }
        }

        return $classes;
    }
}
