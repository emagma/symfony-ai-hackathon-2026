<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpTasksBundle\Llm\LlmTransformerInterface;
use Emagma\McpTasksBundle\Llm\LlmUnavailable;
use Emagma\McpTasksBundle\Llm\OutputRejected;
use Emagma\McpTasksBundle\Llm\OutputSchema;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * llm_transform: a typed answer from a model that has no tool, for a script to use.
 *
 * @internal
 */
#[McpTool(
    name: 'llm_transform',
    description: 'Have a model of the server turn ONE content into a typed value: translate, rewrite, classify,'
        . ' extract. Made for the scripts of a task (create_task), where it is the only way to read a free text.'
        . ' The model has no tool and treats `input` as data. `outputSchema` maps each field of the answer to its'
        . ' type: "string", "int", "number", "bool" or "enum[a,b,c]", with a trailing "?" when optional, e.g.'
        . ' {"gender": "enum[men,women,unisex]", "summary": "string"}. Returns {ok: true, output: {field: value}}'
        . ' with exactly the declared fields. Reasons: schema_invalid, input_too_long, output_rejected (the model'
        . ' answered outside the schema), model_unavailable.',
)]
#[Permission(resource: 'llm', action: Action::Read)]
final readonly class LlmTransform
{
    public function __construct(
        private LlmTransformerInterface $model,
        private int $maxInputLength = 20000,
    ) {
    }

    /**
     * @param string               $instruction  what to do with the content, written by you or the human; never content of the shop
     * @param mixed                $input        the content: a text, or an object of texts
     * @param array<string, string> $outputSchema field => type
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $instruction,
        mixed $input,
        #[Schema(type: 'object', additionalProperties: ['type' => 'string'])]
        array $outputSchema,
    ): array {
        try {
            $schema = OutputSchema::of($outputSchema);
        } catch (\InvalidArgumentException $invalid) {
            return ['ok' => false, 'reason' => 'schema_invalid', 'message' => $invalid->getMessage()];
        }

        $content = \is_string($input) ? $input : (string) json_encode($input, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
        if (mb_strlen($content) > $this->maxInputLength) {
            return ['ok' => false, 'reason' => 'input_too_long', 'message' => \sprintf('The content is longer than %d characters.', $this->maxInputLength)];
        }

        try {
            return ['ok' => true, 'output' => $schema->parse($this->model->transform($instruction, $content, $schema))];
        } catch (OutputRejected $rejected) {
            return ['ok' => false, 'reason' => 'output_rejected', 'message' => $rejected->getMessage()];
        } catch (LlmUnavailable $unavailable) {
            return ['ok' => false, 'reason' => 'model_unavailable', 'message' => $unavailable->getMessage()];
        }
    }
}
