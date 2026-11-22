<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Tests\Unit\FraudCheckerAgent;

use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\DomainCheckerInterface;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\EmailRiskRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EmailRiskRules::class)]
final class EmailRiskRulesTest extends TestCase
{
    #[DataProvider('addresses')]
    public function testAnAddressIsHighAmbiguousOrFine(string $email, ?string $firstName, ?string $lastName, string $expected): void
    {
        $assessment = $this->rules()->assess($email, $firstName, $lastName);

        $actual = match (true) {
            $assessment->isHigh() => 'high',
            $assessment->isAmbiguous() => 'ambiguous',
            default => 'fine',
        };
        self::assertSame($expected, $actual, implode(' ', $assessment->signals));
    }

    /**
     * @return iterable<string, array{string, ?string, ?string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'an ordinary address' => ['jean.dupont@example.com', 'Jean', 'Dupont', 'fine'];
        yield 'a short address is not a risk' => ['jd@example.com', 'Jean', 'Dupont', 'fine'];
        yield 'a nickname unrelated to the name alone' => ['bluesky@example.com', 'Jean', 'Dupont', 'fine'];
        yield 'a disposable address service' => ['jean.dupont@mailinator.com', 'Jean', 'Dupont', 'high'];
        yield 'a domain that accepts no mail' => ['jean@no-mail.test', 'Jean', null, 'high'];
        yield 'malformed' => ['not-an-address', null, null, 'high'];
        yield 'generated, with digits' => ['x7k2qz9p4m@example.com', null, null, 'ambiguous'];
        yield 'generated, no vowels, and unrelated to the name' => ['bcdfghjklm@example.com', 'Jean', 'Dupont', 'ambiguous'];
        yield 'generated on a disposable service' => ['x7k2qz9p4m@yopmail.com', null, null, 'high'];
    }

    public function testTheSignalsAndTheShapeNeverCarryTheAddress(): void
    {
        $assessment = $this->rules()->assess('Jean.Dupont42@Mailinator.com', 'Jean', 'Dupont');

        self::assertSame('aaaa.aaaaaa99@mailinator.com', $assessment->shape);
        self::assertSame('mailinator.com', $assessment->domain);
        self::assertSame(['The email domain is a disposable address service (mailinator.com).'], $assessment->signals);
    }

    public function testADomainThatCannotBeCheckedIsNotHeldAgainstTheAccount(): void
    {
        $unknown = new class() implements DomainCheckerInterface {
            public function acceptsMail(string $domain): ?bool
            {
                return null;
            }
        };

        self::assertSame(0, (new EmailRiskRules($unknown, []))->assess('jean.dupont@example.com')->score);
    }

    private function rules(): EmailRiskRules
    {
        $dns = new class() implements DomainCheckerInterface {
            public function acceptsMail(string $domain): ?bool
            {
                return 'no-mail.test' !== $domain;
            }
        };

        return new EmailRiskRules($dns, ['mailinator.com', 'YOPmail.com']);
    }
}
