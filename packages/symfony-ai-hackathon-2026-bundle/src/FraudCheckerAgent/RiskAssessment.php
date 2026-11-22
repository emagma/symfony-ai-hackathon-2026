<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent;

/**
 * What the rules measured on an address: a score, its signals, and the shape the model may see.
 *
 * @internal
 */
final readonly class RiskAssessment
{
    /** At or above: the rules alone say high. */
    public const int HIGH = 60;

    /** At or above, below HIGH: the rules cannot tell, an agent looks. */
    public const int AMBIGUOUS = 25;

    /**
     * @param list<string> $signals one short sentence each, without the address itself
     */
    public function __construct(
        public int $score,
        public array $signals,
        /** The address with every letter as `a` and every digit as `9`: its shape, not the address. */
        public string $shape,
        public string $domain,
    ) {
    }

    public function isHigh(): bool
    {
        return $this->score >= self::HIGH;
    }

    public function isAmbiguous(): bool
    {
        return $this->score >= self::AMBIGUOUS && $this->score < self::HIGH;
    }
}
