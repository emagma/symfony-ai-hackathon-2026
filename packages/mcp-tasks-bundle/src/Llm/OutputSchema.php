<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Llm;

/**
 * What a model must answer: a flat JSON object, each field of a declared type. A script writes
 * `['gender' => 'enum[men,women]', 'note' => 'string?']`; the answer is checked in code, never trusted.
 *
 * @internal
 */
final readonly class OutputSchema
{
    /**
     * @param array<string, array{type: string, optional: bool, values: list<string>}> $fields
     */
    private function __construct(
        public array $fields,
    ) {
    }

    /**
     * @param array<mixed> $schema field => string, int, number, bool or enum[a,b]; a trailing ? makes it optional
     */
    public static function of(array $schema): self
    {
        if ([] === $schema) {
            throw new \InvalidArgumentException('The output schema names at least one field.');
        }
        $fields = [];
        foreach ($schema as $name => $declared) {
            if (!\is_string($name) || !\is_string($declared) || 1 !== preg_match('/^(string|int|number|bool|enum\[([^\]]+)\])(\?)?$/', trim($declared), $match)) {
                throw new \InvalidArgumentException(\sprintf('Field "%s": a type is string, int, number, bool or enum[a,b], with a trailing ? when optional.', (string) $name));
            }
            $values = '' === ($match[2] ?? '') ? [] : array_map(trim(...), explode(',', $match[2]));
            $fields[$name] = ['type' => [] === $values ? $match[1] : 'enum', 'optional' => '?' === ($match[3] ?? ''), 'values' => $values];
        }

        return new self($fields);
    }

    public function describe(): string
    {
        $lines = [];
        foreach ($this->fields as $name => $field) {
            $type = 'enum' === $field['type'] ? 'one of: ' . implode(', ', $field['values']) : $field['type'];
            $lines[] = \sprintf('- "%s": %s%s', $name, $type, $field['optional'] ? ' (optional, null when unknown)' : '');
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, string|int|float|bool> the declared fields, and only them
     *
     * @throws OutputRejected
     */
    public function parse(string $answer): array
    {
        // Models wrap JSON in a code fence more often than not.
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/', '', trim($answer)) ?? $answer;
        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            throw new OutputRejected('The model did not answer a JSON object.');
        }

        $output = [];
        foreach ($this->fields as $name => $field) {
            $value = $decoded[$name] ?? null;
            if (\is_string($value)) {
                $value = trim($value);
            }
            if (null === $value || '' === $value) {
                if ($field['optional']) {
                    continue;
                }

                throw new OutputRejected(\sprintf('The model left "%s" empty.', $name));
            }
            $valid = match ($field['type']) {
                'string' => \is_string($value),
                'int' => \is_int($value),
                'number' => \is_int($value) || \is_float($value),
                'bool' => \is_bool($value),
                default => \is_string($value) && \in_array($value, $field['values'], true),
            };
            if (!$valid) {
                throw new OutputRejected(\sprintf('The model answered "%s" outside its type.', $name));
            }
            \assert(\is_scalar($value));
            $output[$name] = $value;
        }

        return $output;
    }
}
