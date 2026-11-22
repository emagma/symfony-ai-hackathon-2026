<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Redaction;

/**
 * Scrubs secrets and personal data at persistence only: tool results and previews keep the real values.
 * `targetType` and `targetCode` are not scrubbed, so a write tool must never put personal data in `targetCode`
 * (the journal still turns an email found there into the marker, {@see redactText()}).
 */
final class SensitiveDataRedactor
{
    /**
     * Distinctive on purpose: a revert looks for it. Changing it leaves the entries already scrubbed unrecognised.
     */
    public const string MARKER = '__REDACTED__';

    /**
     * Matched as substrings of a lowercased key. `token` is left out on purpose: it would swallow a resource identifier such as `tokenValue`.
     *
     * @var list<string>
     */
    private const array SECRET_FRAGMENTS = [
        'secret', 'password', 'passphrase', 'apikey', 'api_key',
        'credential', 'privatekey', 'private_key',
    ];

    /**
     * Applied to the values that survive the key rules. Emails only: a phone pattern would also match order numbers and prices.
     */
    public const string EMAIL_PATTERN = '/[\w.!#$%&\'*+\/=?^`{|}~-]+@[\w-]+(?:\.[\w-]+)+/u';

    /** @var list<string> lowercased */
    private readonly array $personalKeys;

    /** @var list<string> lowercased */
    private readonly array $extraSecretFragments;

    /** @var array<string, list<string>> parent key => child keys dropped whole */
    private readonly array $opaqueSubtrees;

    /**
     * @param list<string>                             $extraPersonalKeys    exact key names holding personal data
     * @param list<string>                             $extraSecretFragments substrings marking a key as a secret
     * @param iterable<SensitiveKeysProviderInterface> $providers
     */
    public function __construct(
        array $extraPersonalKeys = [],
        array $extraSecretFragments = [],
        iterable $providers = [],
        private readonly string $marker = self::MARKER,
    ) {
        $personalKeys = [];
        $opaqueSubtrees = [];
        foreach ($providers as $provider) {
            $personalKeys = [...$personalKeys, ...$provider->personalKeys()];
            foreach ($provider->opaqueSubtrees() as $parent => $children) {
                $opaqueSubtrees[$parent] = [...($opaqueSubtrees[$parent] ?? []), ...$children];
            }
        }

        $this->personalKeys = array_values(array_unique(array_map(mb_strtolower(...), [...$personalKeys, ...$extraPersonalKeys])));
        $this->extraSecretFragments = array_values(array_map(mb_strtolower(...), $extraSecretFragments));
        $this->opaqueSubtrees = $opaqueSubtrees;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    public function redact(array $payload): array
    {
        return $this->walk($payload, null);
    }

    /**
     * A diff is keyed by dotted paths (`gateway.config`): one sensitive segment redacts the whole `{before, after}` pair.
     *
     * @param array<string, mixed> $diff
     *
     * @return array<string, mixed>
     */
    public function redactDiff(array $diff): array
    {
        $out = [];
        foreach ($diff as $key => $change) {
            $segments = explode('.', mb_strtolower($key));

            $sensitive = false;
            $previous = null;
            foreach ($segments as $segment) {
                if ($this->isSensitive($segment, $previous)) {
                    $sensitive = true;

                    break;
                }
                $previous = $segment;
            }

            if ($sensitive) {
                $out[$key] = $this->marker;

                continue;
            }

            $out[$key] = \is_array($change) ? $this->walk($change, $previous) : $change;
        }

        return $out;
    }

    public function marker(): string
    {
        return $this->marker;
    }

    /**
     * A free text with every email in it replaced by the marker; the rest is kept.
     */
    public function redactText(string $value): string
    {
        $scrubbed = $this->scrubValue($value);

        return \is_string($scrubbed) ? $scrubbed : $value;
    }

    public static function containsEmail(string $value): bool
    {
        return str_contains($value, '@') && 1 === preg_match(self::EMAIL_PATTERN, $value);
    }

    /**
     * A scrubbed snapshot no longer describes a state the backend could be restored to.
     *
     * @param array<array-key, mixed> $payload
     */
    public function containsRedaction(array $payload): bool
    {
        foreach ($payload as $value) {
            if ($this->marker === $value) {
                return true;
            }
            if (\is_array($value) && $this->containsRedaction($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, mixed>
     */
    private function walk(array $payload, ?string $parentKey): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            $normalised = \is_string($key) ? mb_strtolower($key) : null;

            if (null !== $normalised && $this->isSensitive($normalised, $parentKey)) {
                $out[$key] = $this->marker;

                continue;
            }

            // A list keeps its parent's key: the entries of `addresses` are judged as addresses.
            $out[$key] = \is_array($value)
                ? $this->walk($value, $normalised ?? $parentKey)
                : $this->scrubValue($value);
        }

        return $out;
    }

    private function scrubValue(mixed $value): mixed
    {
        if (!\is_string($value) || !str_contains($value, '@')) {
            return $value;
        }

        return preg_replace(self::EMAIL_PATTERN, $this->marker, $value) ?? $value;
    }

    private function isSensitive(string $key, ?string $parentKey): bool
    {
        if (\in_array($key, $this->personalKeys, true)) {
            return true;
        }

        foreach ([...self::SECRET_FRAGMENTS, ...$this->extraSecretFragments] as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        if (null !== $parentKey && \in_array($key, $this->opaqueSubtrees[$parentKey] ?? [], true)) {
            return true;
        }

        return false;
    }
}
