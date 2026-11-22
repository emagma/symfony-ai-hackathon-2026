<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpPlugin\Tests\Unit\ApiPlatform\Serializer;

use Emagma\SyliusMcpPlugin\ApiPlatform\Lean\LeanRequestDetector;
use Emagma\SyliusMcpPlugin\ApiPlatform\Serializer\LeanTranslationTrimNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Product;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[CoversClass(LeanTranslationTrimNormalizer::class)]
final class LeanTranslationTrimNormalizerTest extends TestCase
{
    private const array EIGHT_LOCALES = [
        'translations' => [
            'de_DE' => ['@id' => '/api/v2/admin/products/MUG/translations/de_DE', '@type' => 'ProductTranslation', 'name' => 'Becher'],
            'en_US' => ['@id' => '/api/v2/admin/products/MUG/translations/en_US', '@type' => 'ProductTranslation', 'name' => 'Mug'],
            'fr_FR' => ['@id' => '/api/v2/admin/products/MUG/translations/fr_FR', '@type' => 'ProductTranslation', 'name' => 'Tasse'],
        ],
        'code' => 'MUG',
    ];

    public function testItKeepsOnlyTheAnnouncedLocaleAndDropsTheJsonLdEnvelope(): void
    {
        $result = $this->normalize(self::EIGHT_LOCALES, 'respond-for=lean; locale=fr_FR');

        self::assertSame(['fr_FR' => ['name' => 'Tasse']], $result['translations']);
        // Everything else is the inner normalizer's business.
        self::assertSame('MUG', $result['code']);
    }

    /**
     * A caller that did not say what it reads has not agreed to lose the rest -
     * lean mode is not by itself a licence to narrow the payload.
     */
    public function testItLeavesThePayloadAloneWhenNoLocaleIsAnnounced(): void
    {
        $result = $this->normalize(self::EIGHT_LOCALES, 'respond-for=lean');

        self::assertSame(self::EIGHT_LOCALES['translations'], $result['translations']);
    }

    public function testItDoesNotRunOutsideLeanMode(): void
    {
        $result = $this->normalize(self::EIGHT_LOCALES, null);

        self::assertSame(self::EIGHT_LOCALES['translations'], $result['translations']);
    }

    /**
     * Mirrors resolveTranslation() in the tools: a product translated only in
     * German still has a name. Trimming to an absent locale would have turned
     * that into null - data loss dressed up as an optimisation.
     */
    public function testItFallsBackToAnAvailableLocaleRatherThanEmptyingTheField(): void
    {
        $result = $this->normalize(self::EIGHT_LOCALES, 'respond-for=lean; locale=zz_ZZ');

        self::assertSame(['de_DE' => ['name' => 'Becher']], $result['translations']);
    }

    public function testItIgnoresAPayloadWithoutTranslations(): void
    {
        $result = $this->normalize(['code' => 'MUG'], 'respond-for=lean; locale=fr_FR');

        self::assertSame(['code' => 'MUG'], $result);
    }

    public function testItToleratesTranslationsThatAreNotObjects(): void
    {
        // Some contexts serialize them as IRIs rather than embedding them.
        $result = $this->normalize(
            ['translations' => ['fr_FR' => '/api/v2/admin/products/MUG/translations/fr_FR']],
            'respond-for=lean; locale=fr_FR',
        );

        self::assertSame(
            ['fr_FR' => '/api/v2/admin/products/MUG/translations/fr_FR'],
            $result['translations'],
        );
    }

    public function testItRefusesToRecurseIntoItself(): void
    {
        $normalizer = $this->subject('respond-for=lean; locale=fr_FR');

        self::assertFalse($normalizer->supportsNormalization(
            new Product(),
            null,
            ['__mcp_lean_translation_trim_called' => true],
        ));
        self::assertTrue($normalizer->supportsNormalization(new Product()));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function normalize(array $payload, ?string $prefer): array
    {
        $normalizer = $this->subject($prefer);

        $inner = $this->createMock(NormalizerInterface::class);
        $inner->method('normalize')->willReturn($payload);
        $normalizer->setNormalizer($inner);

        /** @var array<string, mixed> $result */
        $result = $normalizer->normalize(new Product());

        return $result;
    }

    private function subject(?string $prefer): LeanTranslationTrimNormalizer
    {
        $request = new Request();
        if (null !== $prefer) {
            $request->headers->set('Prefer', $prefer);
        }
        $stack = new RequestStack();
        $stack->push($request);

        return new LeanTranslationTrimNormalizer(new LeanRequestDetector($stack));
    }
}
