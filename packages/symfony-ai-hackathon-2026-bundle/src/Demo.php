<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle;

/**
 * One demo of the tour: what to type, what to look at, and the Symfony AI notions behind it.
 *
 * @internal
 */
final readonly class Demo
{
    /**
     * @param list<string> $prompts   what the user types in the chat, in order
     * @param list<string> $symfonyAi the Symfony AI notions the demo exercises
     * @param list<string> $code      where to read the code, paths from the repository root
     */
    public function __construct(
        public string $name,
        public int $position,
        public string $title,
        public string $user,
        public array $prompts,
        public array $symfonyAi,
        public array $code,
        public string $guide,
    ) {
    }
}
