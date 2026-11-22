<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent;

/**
 * Tells whether a domain accepts mail. Replace it in tests so that no DNS is queried.
 */
interface DomainCheckerInterface
{
    /**
     * Whether the domain has a mail server; null when it cannot be told (no resolver, timeout).
     */
    public function acceptsMail(string $domain): ?bool;
}
