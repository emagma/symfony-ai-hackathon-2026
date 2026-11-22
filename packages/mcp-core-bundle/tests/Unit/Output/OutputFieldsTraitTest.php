<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Tests\Unit\Output;

use Emagma\McpCoreBundle\Output\Identifier;
use Emagma\McpCoreBundle\Output\OutputFieldsTrait;
use Emagma\McpCoreBundle\Output\Personal;
use Emagma\McpCoreBundle\Output\Surplus;
use Emagma\McpCoreBundle\Output\Untrusted;
use Emagma\McpCoreBundle\Privacy\PrivacyContext;
use Emagma\McpCoreBundle\Privacy\PrivacyMode;
use Emagma\McpCoreBundle\Privacy\Pseudonymiser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutputFieldsTrait::class)]
final class OutputFieldsTraitTest extends TestCase
{
    protected function tearDown(): void
    {
        PrivacyContext::reset();
    }

    public function testTheMarkNamesTheUntrustedFieldsThatAreSetAndComesLast(): void
    {
        $json = $this->json($this->dto(comment: null));

        self::assertSame(['code', 'email', 'comment', 'birthday', 'untrustedContent'], array_keys($json));
        self::assertSame(['email'], $json['untrustedContent']['fields'], 'a null field has nothing to quote');
        self::assertArrayNotHasKey('notice', $json['untrustedContent'], 'the notice travels once per response, not per item');
    }

    public function testADtoWithNoUntrustedValueCarriesNoMark(): void
    {
        self::assertArrayNotHasKey('untrustedContent', $this->json($this->dto(email: null, comment: null)));
    }

    public function testUnderPseudonymiseAnIdentifierBecomesATokenAndSurplusIsLeftOut(): void
    {
        PrivacyContext::configure(PrivacyMode::Pseudonymise, new Pseudonymiser('s3cret'));
        $dto = $this->dto();

        $json = $this->json($dto);

        self::assertMatchesRegularExpression('/^email:[0-9a-f]{8}$/', $json['email']);
        self::assertSame($json['email'], $this->json($this->dto())['email'], 'the same value is the same token');
        self::assertArrayNotHasKey('birthday', $json, 'surplus is absent, not null');
        self::assertSame(['comment'], $json['untrustedContent']['fields'], 'a token is the server\'s, not the public\'s');

        PrivacyContext::revealThisResponse();
        $revealed = $this->json($dto);
        self::assertSame('ada@example.com', $revealed['email']);
        self::assertSame('1815-12-10', $revealed['birthday']);
        self::assertSame(['email', 'comment'], $revealed['untrustedContent']['fields']);
    }

    private function dto(?string $email = 'ada@example.com', ?string $comment = 'Great.'): \JsonSerializable
    {
        return new class('C-1', $email, $comment, '1815-12-10') implements \JsonSerializable {
            use OutputFieldsTrait;

            public function __construct(
                public string $code,
                #[Untrusted, Personal, Identifier]
                public ?string $email,
                #[Untrusted]
                public ?string $comment,
                #[Personal, Surplus]
                public ?string $birthday,
            ) {
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\JsonSerializable $dto): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(json_encode($dto, \JSON_THROW_ON_ERROR), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
