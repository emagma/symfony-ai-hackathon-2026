<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpServer\Tests\Qa;

/** No test writes Boho_Beach_Breeze, so the detection stays meaningful on a rerun. */
final class Scenario07ProductTranslationTest extends QaScenarioTestCase
{
    private const array FIELDS = ['name', 'shortDescription', 'metaDescription'];

    public function testAMissingGermanTranslationIsSeenAsMissing(): void
    {
        $english = $this->product('Boho_Beach_Breeze', 'en_US');
        self::assertNotNull($english['name'], 'the en_US name is read');

        // GAP: a locale without a translation is served the text of another locale.
        $german = $this->product('Boho_Beach_Breeze', 'de_DE');
        foreach (self::FIELDS as $field) {
            self::assertNull($german[$field], sprintf('de_DE %s is missing, not the en_US text', $field));
        }
    }

    public function testTheGermanTranslationIsWrittenAndReadBackPerLocale(): void
    {
        $code = 'Sunshine_Strappy_Delight';
        $english = $this->product($code, 'en_US');
        $german = [
            'name' => 'Sonnenschein Träger-Kleid',
            'slug' => 'sonnenschein-traeger-kleid',
            'shortDescription' => 'Ein leichtes Sommerkleid mit schmalen Trägern.',
            'metaDescription' => 'Leichtes Sommerkleid mit schmalen Trägern - jetzt im Fashion Web Store entdecken.',
        ];

        $result = $this->call('upsert_products', ['items' => [['code' => $code, 'locale' => 'de_DE'] + $german], 'mode' => 'update', 'confirm' => true]);
        self::assertBatchApplied($result);

        $read = $this->product($code, 'de_DE');
        foreach (self::FIELDS as $field) {
            self::assertSame($german[$field], $read[$field], 'de_DE ' . $field);
        }
        // Only the fields en_US holds: an empty one reads another locale's text.
        $englishAfter = $this->product($code, 'en_US');
        foreach (['name', 'shortDescription'] as $field) {
            self::assertSame($english[$field], $englishAfter[$field], 'en_US ' . $field);
        }
    }

    /**
     * @return array{name: ?string, shortDescription: ?string, metaDescription: ?string}
     */
    private function product(string $code, string $locale): array
    {
        $result = $this->call('get_products', ['codes' => [$code], 'locale' => $locale]);
        self::assertSame([], $result['missing']);
        /** @var list<array{name: ?string, shortDescription: ?string, metaDescription: ?string}> $found */
        $found = $result['found'];

        return $found[0];
    }
}
