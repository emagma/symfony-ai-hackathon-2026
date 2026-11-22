<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent;

use Emagma\SymfonyAiHackathon2026Bundle\Exception\JudgementFailedException;

/**
 * Settles the accounts the rules cannot. Replace it to settle them another way.
 */
interface RiskJudgeInterface
{
    /**
     * Settles a case the rules left ambiguous.
     *
     * @throws JudgementFailedException when the model cannot be reached or answers something unusable
     */
    public function judge(RiskAssessment $assessment, bool $hasName): Judgement;
}
