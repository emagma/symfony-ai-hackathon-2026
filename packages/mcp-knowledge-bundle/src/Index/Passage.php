<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Index;

/**
 * A section of a procedure, as a search returns it: what it says and where it comes from.
 */
final readonly class Passage
{
    public function __construct(
        public string $title,
        public string $section,
        public string $text,
        public string $file,
        public ?string $team,
        public ?string $updated,
    ) {
    }
}
