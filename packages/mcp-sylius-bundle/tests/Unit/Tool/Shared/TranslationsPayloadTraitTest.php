<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tests\Unit\Tool\Shared;

use Emagma\McpSyliusBundle\Testing\McpToolTestCase;
use Emagma\McpSyliusBundle\Tool\Shared\TranslationsPayloadTrait;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TranslationsPayloadTrait::class)]
final class TranslationsPayloadTraitTest extends McpToolTestCase
{
    use TranslationsPayloadTrait;

    public function testItReadsTheEnabledLocalesAndPutsThePrimaryFirst(): void
    {
        $client = $this->apiClient();
        $client->expects(self::once())
            ->method('getDecoded')
            ->with('locales', ['itemsPerPage' => 100])
            ->willReturn($this->collection([
                ['code' => 'fr_FR'],
                ['code' => 'en_US'],
                ['code' => 'de_DE'],
            ]));

        self::assertSame(
            ['en_US', 'fr_FR', 'de_DE'],
            $this->enabledLocales($client, 'en_US'),
        );
    }

    public function testItFallsBackToThePrimaryLocaleWhenTheCollectionCannotBeRead(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willThrowException($this->clientException(500));

        // A create that may still succeed beats one that fails on a diagnostic call.
        self::assertSame(['en_US'], $this->enabledLocales($client, 'en_US'));
    }

    public function testItFallsBackWhenTheCollectionIsEmpty(): void
    {
        $client = $this->apiClient();
        $client->method('getDecoded')->willReturn($this->collection([]));

        self::assertSame(['en_US'], $this->enabledLocales($client, 'en_US'));
    }

    public function testItReplicatesARichTranslationAcrossEveryLocale(): void
    {
        $filled = self::fillTranslations(
            ['en_US', 'fr_FR'],
            ['name' => 'Cats', 'slug' => 'cats', 'description' => 'Feline things'],
        );

        self::assertSame([
            'en_US' => ['name' => 'Cats', 'slug' => 'cats', 'description' => 'Feline things', 'locale' => 'en_US'],
            'fr_FR' => ['name' => 'Cats', 'slug' => 'cats', 'description' => 'Feline things', 'locale' => 'fr_FR'],
        ], $filled);
    }

    public function testItStampsTheRightLocaleEvenWhenThePayloadCarriesOne(): void
    {
        // A caller passing the primary translation verbatim would otherwise stamp every locale with it.
        $filled = self::fillTranslations(['en_US', 'fr_FR'], ['name' => 'Cats', 'locale' => 'en_US']);

        self::assertSame('en_US', $filled['en_US']['locale']);
        self::assertSame('fr_FR', $filled['fr_FR']['locale']);
    }

    public function testNameOverridesWinPerLocale(): void
    {
        $filled = self::fillNameTranslations(
            ['en_US', 'fr_FR', 'de_DE'],
            'Similar products',
            ['fr_FR' => 'Produits similaires'],
        );

        self::assertSame('Similar products', $filled['en_US']['name']);
        self::assertSame('Produits similaires', $filled['fr_FR']['name']);
        self::assertSame('Similar products', $filled['de_DE']['name']);
    }

    public function testAnOverrideForAnUnknownLocaleIsIgnored(): void
    {
        $filled = self::fillNameTranslations(['en_US'], 'Name', ['zz_ZZ' => 'Ghost']);

        self::assertSame(['en_US'], array_keys($filled));
    }

    public function testTheLocaleNameMapDropsJunk(): void
    {
        self::assertSame(
            ['fr_FR' => 'Nom'],
            self::localeNameMap(['fr_FR' => '  Nom  ', 'en_US' => '', 'x' => 42, 5 => 'ignored']),
        );
        self::assertSame([], self::localeNameMap('not an array'));
    }
}
