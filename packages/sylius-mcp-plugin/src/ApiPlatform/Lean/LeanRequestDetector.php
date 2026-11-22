<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\ApiPlatform\Lean;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tells whether a request asked for lean payloads (`Prefer: respond-for=lean`) and which locale it
 * named (`locale=fr_FR`). The one place that parses the header: the context builder and the
 * filters all read from it.
 */
final readonly class LeanRequestDetector
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function prefersLeanResponse(?Request $request = null): bool
    {
        $request ??= $this->requestStack->getMainRequest();
        if (null === $request) {
            return false;
        }

        // RFC 7240 §3 - Prefer may carry multiple comma-separated tokens, each
        // optionally repeated across multiple headers. Token values may be
        // unquoted or double-quoted. Match `respond-for=lean` anywhere in the set.
        foreach ($request->headers->all('prefer') as $headerValue) {
            if (!\is_string($headerValue)) {
                continue;
            }
            foreach (explode(',', $headerValue) as $token) {
                if (1 === preg_match('/^\s*respond-for\s*=\s*"?lean"?\s*(?:;|$)/i', $token)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The locale the caller announced on the lean preference, if any.
     *
     * Returns null when absent or malformed - the trimming that reads this then
     * leaves the payload alone, so a missing parameter costs bandwidth and
     * never correctness.
     */
    public function requestedLocale(?Request $request = null): ?string
    {
        $request ??= $this->requestStack->getMainRequest();
        if (null === $request) {
            return null;
        }

        foreach ($request->headers->all('prefer') as $headerValue) {
            if (!\is_string($headerValue)) {
                continue;
            }
            foreach (explode(',', $headerValue) as $token) {
                if (1 !== preg_match('/respond-for\s*=\s*"?lean"?/i', $token)) {
                    continue;
                }
                // Sylius locale codes are `xx_XX` / `xx`; anything else is not
                // one, and guessing would be worse than ignoring it.
                if (1 === preg_match('/;\s*locale\s*=\s*"?([A-Za-z]{2}(?:_[A-Za-z0-9]{2,8})?)"?/', $token, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }
}
