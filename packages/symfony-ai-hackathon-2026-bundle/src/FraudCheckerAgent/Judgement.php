<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent;

use Emagma\McpAgentsBundle\Level;

/**
 * What a judge answers for an ambiguous account: a level and a short explanation.
 *
 * @internal
 */
final readonly class Judgement
{
    public function __construct(
        public Level $level,
        public string $explanation,
    ) {
    }
}
