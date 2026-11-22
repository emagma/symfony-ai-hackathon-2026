<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpGuidelinesBundle\Guideline;
use Emagma\McpGuidelinesBundle\GuidelineResolver;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * Gives the model the guidelines of the intents it is about to write for.
 *
 * @internal
 */
#[McpTool(
    name: 'get_guidelines',
    description: 'Get the guidelines of the business before writing a text for it: who it is and how it writes'
        . ' this kind of content. `intents`: what you are about to write, from the list of intents in the server\'s'
        . ' instructions. Returns {guidelines: [{name, content}]}, each guideline once, to follow in the text; an'
        . ' unknown intent adds `unknown_intents` and the valid `intents`.',
)]
#[Permission(resource: 'guideline', action: Action::Read)]
final readonly class GetGuidelines
{
    public function __construct(
        private GuidelineResolver $resolver,
    ) {
    }

    /**
     * @param list<string> $intents
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        #[Schema(type: 'array', items: ['type' => 'string'], minItems: 1)]
        array $intents,
    ): array {
        $found = $this->resolver->forIntents(array_values(array_filter($intents, 'is_string')));
        $answer = ['guidelines' => array_map(
            static fn (Guideline $guideline): array => ['name' => $guideline->name, 'content' => $guideline->content],
            $found['guidelines'],
        )];
        if ([] !== $found['unknown']) {
            $answer['unknown_intents'] = $found['unknown'];
            $answer['intents'] = array_keys($this->resolver->intents());
        }

        return $answer;
    }
}
