<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Command;

use Emagma\SyliusAssistantTestChat\Catalog\PriceBook;
use Emagma\SyliusAssistantTestChat\Catalog\ProviderCatalog;
use Emagma\SyliusAssistantTestChat\Catalog\ProviderModels;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'testchat:catalog', description: 'List the chat models of each provider and whether Langfuse prices them')]
final class CatalogCommand
{
    public function __construct(
        private readonly ProviderCatalog $catalog,
        private readonly ProviderModels $models,
        private readonly PriceBook $prices,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Only this provider, with every model and its prices')] ?string $provider = null): int
    {
        if (null !== $provider) {
            $rows = [];
            foreach ($this->models->list($this->catalog->get($provider)) as $model) {
                $prices = $this->prices->prices($model);
                $rows[] = [$model, null === $prices ? '-' : implode(' · ', array_map(
                    static fn (string $type, float $price): string => \sprintf('%s $%s', $type, rtrim(rtrim(number_format($price * 1_000_000, 4), '0'), '.')),
                    array_keys($prices),
                    $prices,
                ))];
            }
            $io->table(['model', 'Langfuse price per 1M tokens'], $rows);

            return 0;
        }

        $rows = [];
        foreach ($this->catalog->all() as $p) {
            try {
                $models = $this->models->list($p);
                $priced = \count(array_filter($models, fn (string $model): bool => null !== $this->prices->prices($model)));
                $rows[] = [$p->label, $p->hasApiKey ? 'yes' : 'no', \count($models), $priced];
            } catch (\Throwable $e) {
                $rows[] = [$p->label, $p->hasApiKey ? 'yes' : 'no', 'error: ' . $e->getMessage(), '-'];
            }
        }
        $io->table(['provider', 'key', 'chat models', 'priced by Langfuse'], $rows);

        return 0;
    }
}
