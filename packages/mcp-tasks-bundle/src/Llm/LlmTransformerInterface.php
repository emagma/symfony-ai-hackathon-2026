<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Llm;

/**
 * Asks a model of the server's own provider, without any tool. The raw answer: the schema checks it.
 */
interface LlmTransformerInterface
{
    /**
     * @throws LlmUnavailable
     */
    public function transform(string $instruction, string $input, OutputSchema $schema): string;
}
