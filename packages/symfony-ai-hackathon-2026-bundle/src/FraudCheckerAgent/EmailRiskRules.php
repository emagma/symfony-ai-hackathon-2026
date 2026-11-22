<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent;

/**
 * What can be measured on an email address, in code: exact, free, and the same for every account. The
 * model only sees the cases these rules cannot settle.
 *
 * @internal
 */
final readonly class EmailRiskRules
{
    /** @var array<string, true> */
    private array $disposable;

    /**
     * @param list<string> $disposableDomains
     */
    public function __construct(
        private DomainCheckerInterface $domains,
        array $disposableDomains,
    ) {
        $this->disposable = array_fill_keys(array_map(strtolower(...), $disposableDomains), true);
    }

    public function assess(string $email, ?string $firstName = null, ?string $lastName = null): RiskAssessment
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        if (false === $at || false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            return new RiskAssessment(RiskAssessment::HIGH, ['The email address is malformed.'], self::shape($email), '');
        }
        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);

        $score = 0;
        $signals = [];
        if (isset($this->disposable[$domain])) {
            $score += 60;
            $signals[] = \sprintf('The email domain is a disposable address service (%s).', $domain);
        } elseif (false === $this->domains->acceptsMail($domain)) {
            $score += 60;
            $signals[] = 'The email domain accepts no mail (no mail server in its DNS).';
        }
        if (self::looksGenerated($local)) {
            $score += 30;
            $signals[] = 'The part before the @ looks randomly generated.';
        }
        if (self::unrelatedToName($local, $firstName, $lastName)) {
            $score += 10;
            $signals[] = 'The address does not relate to the name the customer gave.';
        }

        return new RiskAssessment($score, $signals, self::shape($local) . '@' . $domain, $domain);
    }

    public static function shape(string $text): string
    {
        return preg_replace(['/[a-z]/i', '/\d/'], ['a', '9'], $text) ?? '';
    }

    private static function looksGenerated(string $local): bool
    {
        $plain = preg_replace('/[^a-z0-9]/', '', $local) ?? '';
        if (\strlen($plain) < 8) {
            return false;
        }
        $digits = preg_match_all('/\d/', $plain);
        $letters = preg_replace('/\d/', '', $plain) ?? '';
        $vowels = preg_match_all('/[aeiouy]/', $letters);

        // Many digits mixed in, or letters almost without vowels: "x7k2qz9p4m", "bcdfghjklm".
        return $digits >= 4 && \strlen($letters) >= 3 ||
            (\strlen($letters) >= 8 && $vowels / \strlen($letters) < 0.15) ||
            1 === preg_match('/[bcdfghjklmnpqrstvwxz]{6,}/', $letters);
    }

    private static function unrelatedToName(string $local, ?string $firstName, ?string $lastName): bool
    {
        $names = array_filter(
            array_map(static fn (?string $name): string => preg_replace('/[^a-z]/', '', strtolower(self::ascii($name ?? ''))) ?? '', [$firstName, $lastName]),
            static fn (string $name): bool => \strlen($name) >= 3,
        );
        if ([] === $names) {
            return false;
        }
        $local = preg_replace('/[^a-z]/', '', $local) ?? '';
        foreach ($names as $name) {
            if (str_contains($local, $name) || str_contains($local, substr($name, 0, 4))) {
                return false;
            }
        }

        return \strlen($local) >= 5;
    }

    private static function ascii(string $text): string
    {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);

        return false === $ascii ? $text : $ascii;
    }
}
