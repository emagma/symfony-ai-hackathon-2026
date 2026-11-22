<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\ProductTranslation\Tool;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Caller\ActorProviderInterface;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Resource;
use Emagma\McpSyliusBundle\AdminApi\Adapter\Route;
use Emagma\McpSyliusBundle\AdminApi\AdminApiClientInterface;
use Emagma\McpTasksBundle\Reading\ReadingFailed;
use Emagma\McpTasksBundle\Script\ScriptRefused;
use Emagma\McpTasksBundle\TaskManager;
use Emagma\SymfonyAiHackathon2026Bundle\ProductTranslation\TranslationTask;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;

/**
 * The MCP tool `product_translate`: prepares the task that translates the products without a translation, writes nothing.
 *
 * @internal
 */
#[McpTool(
    name: 'product_translate',
    description: 'Prepare the background task that translates the products that have NO translation yet in a'
        . ' locale: name, description, short description and meta fields, from the source locale, by a model'
        . ' that follows the translation rules of the shop, with a slug. Existing'
        . ' translations are never overwritten. A shortcut for create_task with ready-made scripts: nothing runs'
        . ' and nothing is written yet, the answer is a draft task and its `reading`. Show the reading to the'
        . ' human, then start_task launches it (it writes product after product, as it goes) and get_task'
        . ' follows it. Narrow with taxon (a taxon code) or codes (product codes, at most 200). Reasons:'
        . ' no_actor, same_locale, locale_not_found, too_many_codes, plus those of create_task.',
)]
#[Permission(resource: 'product', action: Action::Write)]
#[Permission(resource: 'task', action: Action::Write, domain: 'core')]
#[Permission(resource: 'llm', action: Action::Read, domain: 'core')]
final readonly class ProductTranslate
{
    private const int MAX_CODES = 200;

    public function __construct(
        private AdminApiClientInterface $client,
        private TranslationTask $translation,
        private TaskManager $tasks,
        private ActorProviderInterface $actors,
        private string $defaultLocale,
    ) {
    }

    /**
     * @param string            $locale       the locale to translate into, e.g. de_DE
     * @param string|null       $sourceLocale the locale to translate from; the shop's default when omitted
     * @param string|null       $taxon        only the products of this taxon
     * @param list<string>|null $codes        only these products
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $locale,
        ?string $sourceLocale = null,
        ?string $taxon = null,
        #[Schema(type: 'array', items: ['type' => 'string'])]
        ?array $codes = null,
    ): array {
        $actor = $this->actors->actor();
        if (null === $actor) {
            return ['ok' => false, 'reason' => 'no_actor'];
        }
        $sourceLocale ??= $this->defaultLocale;
        if ($locale === $sourceLocale) {
            return ['ok' => false, 'reason' => 'same_locale', 'hint' => 'locale and sourceLocale must differ.'];
        }
        $codes = array_values(array_unique($codes ?? []));
        if (\count($codes) > self::MAX_CODES) {
            return ['ok' => false, 'reason' => 'too_many_codes', 'hint' => \sprintf('At most %d codes: narrow with taxon instead.', self::MAX_CODES)];
        }
        foreach ([$locale, $sourceLocale] as $code) {
            $found = $this->client->getDecodedMany([$code => Route::item(Resource::Locale, $code)])[$code] ?? null;
            if (null === $found || true !== $found['ok']) {
                return ['ok' => false, 'reason' => 'locale_not_found', 'locale' => $code, 'hint' => 'The shop has no such locale: search_locales lists them.'];
            }
        }

        $scope = [] !== $codes ? \sprintf('%d product(s)', \count($codes)) : (null !== $taxon ? \sprintf('the products of the taxon %s', $taxon) : 'the whole catalogue');

        try {
            $task = $this->tasks->create(
                $actor,
                \sprintf('Translate the products into %s', $locale),
                \sprintf(
                    "On what: %s, those without a name in %s.\n\nWhat changes: their name, descriptions, meta fields and slug in %s, translated from %s.\n\nWhat does not change: what is already translated, the other locales, prices, stocks, categories.",
                    $scope,
                    $locale,
                    $locale,
                    $sourceLocale,
                ),
                $this->translation->params($sourceLocale, $locale, $taxon, $codes),
                [['name' => 'translate', 'script' => $this->translation->script()]],
            );
        } catch (ScriptRefused $refused) {
            return ['ok' => false, 'reason' => $refused->reason, 'message' => $refused->getMessage()];
        } catch (ReadingFailed $failed) {
            return ['ok' => false, 'reason' => 'reading_failed', 'message' => $failed->getMessage()];
        }

        return [
            'ok' => true,
            'taskId' => $task->getId(),
            'status' => $task->getStatus()->value,
            'reading' => $task->getReading(),
            'manifest' => $task->getManifest(),
            'next' => 'Nothing runs yet. Show the reading to the human as it is; start_task launches the task once they agree, get_task follows it.',
        ];
    }
}
