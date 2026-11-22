<?php

// The step of a product_translate task: the products that have no name in the target locale get one,
// translated from the source locale. Nothing already translated is overwritten.

function translateProduct(string $code): void
{
    $source = \call('get_products', ['codes' => [$code], 'locale' => SOURCE_LOCALE])['found'][0] ?? null;
    if ($source === null) {
        throw new \RuntimeException(\sprintf('Product %s not found.', $code));
    }
    if (\trim((string) ($source['name'] ?? '')) === '') {
        return; // nothing to translate from
    }

    // A text asked in a locale without a translation falls back to another one: only this list tells.
    if (\in_array(TARGET_LOCALE, $source['translatedLocales'] ?? [], true)) {
        return; // already translated: never overwritten
    }

    $texts = [];
    foreach (FIELDS as $field) {
        $text = \trim((string) ($source[$field] ?? ''));
        if ($text !== '') {
            $texts[$field] = $text;
        }
    }

    $schema = \array_fill_keys(\array_keys($texts), 'string') + ['slug' => 'string'];
    $translated = \call('llm_transform', ['instruction' => INSTRUCTION, 'input' => $texts, 'outputSchema' => $schema])['output'];

    $slug = \trim((string) \preg_replace('/[^a-z0-9]+/', '-', \strtolower((string) $translated['slug'])), '-');
    if ($slug === '') {
        throw new \RuntimeException(\sprintf('No usable slug for product %s.', $code));
    }

    $result = \call('upsert_products', [
        // upsert_products takes the locale per item.
        'items' => [['code' => $code, 'locale' => TARGET_LOCALE, 'slug' => $slug] + \array_intersect_key($translated, $texts)],
        'mode' => 'update',
    ]);
    $written = $result['results'][$code] ?? null;
    if ($written === null || $written['ok'] !== true) {
        throw new \RuntimeException(\sprintf('Translation refused for product %s: %s.', $code, $written['reason'] ?? 'unexpected answer'));
    }
}

if (CODES !== []) {
    foreach (CODES as $code) {
        \translateProduct($code);
    }

    return;
}

$page = 1;
do {
    $search = ['locale' => SOURCE_LOCALE, 'page' => $page++, 'itemsPerPage' => 50];
    if (TAXON !== null) {
        $search['taxon'] = TAXON;
    }
    $list = \call('search_products', $search);
    foreach ($list['items'] as $found) {
        \translateProduct($found['code']);
    }
} while ($list['hasMore']);
