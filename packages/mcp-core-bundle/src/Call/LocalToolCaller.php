<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Call;

use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Capability\Registry\ReferenceHandler;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Result\CallToolResult;
use Psr\Container\ContainerInterface;

/**
 * Calls a tool by its name from the host itself (a worker, a command), without a `tools/call` nor a session:
 * same registry, hence same grants of whoever the process acts as, same schema, same tool.
 * There is nobody to elicit: `confirm` is set, because the caller of this class answers for the validation.
 */
final class LocalToolCaller
{
    private readonly ReferenceHandler $handler;

    private readonly SchemaValidator $validator;

    /**
     * The server is only asked for so that the container builds it: building the server is what fills the registry.
     */
    // @phpstan-ignore constructor.unusedParameter
    public function __construct(
        private readonly RegistryInterface $registry,
        ?ContainerInterface $toolLocator = null,
        ?object $server = null,
    ) {
        $this->handler = new ReferenceHandler($toolLocator);
        $this->validator = new SchemaValidator();
    }

    /**
     * Whether the tool exists and the current caller may call it.
     */
    public function has(string $tool): bool
    {
        return $this->registry->hasTool($tool);
    }

    /**
     * The tools the current caller may call, by name.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];
        foreach ($this->registry->getTools()->references as $tool) {
            $names[] = $tool->name;
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> what the tool answered, as plain data
     *
     * @throws LocalToolCallFailed
     */
    public function call(string $tool, array $arguments): array
    {
        try {
            $reference = $this->registry->getTool($tool);
        } catch (ToolNotFoundException) {
            // Forbidden answers as unknown: which one it is is not the caller's to know.
            throw new LocalToolCallFailed(\sprintf('The tool "%s" is unknown, or not allowed.', $tool));
        }

        $schema = $reference->tool->inputSchema;
        $properties = $schema['properties'] ?? [];
        if (\is_array($properties) && isset($properties['confirm'])) {
            $arguments['confirm'] = true;
        }

        $errors = $this->validator->validateAgainstJsonSchema([] === $arguments ? new \stdClass() : $arguments, $schema);
        if ([] !== $errors) {
            $messages = array_map(static fn (array $error): string => trim($error['pointer'] . ' ' . $error['message']), \array_slice($errors, 0, 5));

            throw new LocalToolCallFailed(\sprintf('Invalid arguments for "%s": %s', $tool, implode('; ', $messages)));
        }

        try {
            $result = $this->handler->handle($reference, $arguments + ['_session' => null, '_request' => null]);
        } catch (\Throwable $exception) {
            throw new LocalToolCallFailed($exception->getMessage(), previous: $exception);
        }

        if ($result instanceof CallToolResult) {
            if ($result->isError) {
                throw new LocalToolCallFailed(\sprintf('The tool "%s" answered an error.', $tool));
            }
            $result = $result->structuredContent;
        }

        $encoded = json_encode($result, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $data = \is_string($encoded) ? json_decode($encoded, true) : null;
        if (!\is_array($data)) {
            return ['value' => $data];
        }
        $named = [];
        foreach ($data as $name => $value) {
            $named[(string) $name] = $value;
        }

        return $named;
    }
}
