<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent;

/**
 * Asks the DNS whether a domain has a mail server.
 *
 * @internal
 */
final readonly class DnsDomainChecker implements DomainCheckerInterface
{
    public function acceptsMail(string $domain): ?bool
    {
        if (!\function_exists('checkdnsrr')) {
            return null;
        }
        // A domain without MX still receives mail on its A record (RFC 5321).
        $fqdn = rtrim($domain, '.') . '.';

        return @checkdnsrr($fqdn, 'MX') || @checkdnsrr($fqdn, 'A') || @checkdnsrr($fqdn, 'AAAA');
    }
}
