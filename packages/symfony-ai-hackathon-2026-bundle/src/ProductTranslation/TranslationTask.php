<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\ProductTranslation;

/**
 * The scripts of a translation task: one step, always the same, and the params that say what to translate.
 * Its translation rules are read now and frozen in the instruction the model will be given.
 *
 * @internal
 */
final readonly class TranslationTask
{
    public const array FIELDS = ['name', 'description', 'shortDescription', 'metaKeywords', 'metaDescription'];

    /** What a translation keeps: terms never translated, units, markup. The product translation's own rules. */
    public const string RULES = __DIR__ . '/../../resources/product_translation/prompts/translation.md';

    public const string SCRIPT = __DIR__ . '/../../resources/product_translation/task/translate.php';

    /**
     * @param list<string> $codes
     */
    public function params(string $sourceLocale, string $targetLocale, ?string $taxon, array $codes): string
    {
        $instruction = trim(\sprintf(
            "Translate the texts of this product of an online shop from the locale %s to the locale %s.\n"
            . "Translate every field you are given: same meaning, same tone, same markup.\n"
            . "Also answer `slug`: the translated name as a URL slug, lowercase ASCII letters and digits separated by dashes.\n\n%s",
            $sourceLocale,
            $targetLocale,
            trim((string) file_get_contents(self::RULES)),
        ));

        return "<?php\n\n"
            . 'const SOURCE_LOCALE = ' . self::literal($sourceLocale) . ";\n"
            . 'const TARGET_LOCALE = ' . self::literal($targetLocale) . ";\n"
            . 'const TAXON = ' . (null === $taxon ? 'null' : self::literal($taxon)) . ";\n"
            . 'const CODES = [' . implode(', ', array_map(self::literal(...), $codes)) . "];\n"
            . 'const FIELDS = [' . implode(', ', array_map(self::literal(...), self::FIELDS)) . "];\n"
            . 'const INSTRUCTION = ' . self::literal($instruction) . ";\n";
    }

    public function script(): string
    {
        return (string) file_get_contents(self::SCRIPT);
    }

    private static function literal(string $value): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
}
