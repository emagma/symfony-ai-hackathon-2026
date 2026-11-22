<?php

declare(strict_types=1);

namespace Emagma\SyliusAssistantTestChat\Command;

use Emagma\SyliusAssistantTestChat\Catalog\LangfusePriceDeclarer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'testchat:prices', description: 'Declare into Langfuse the model prices it does not maintain (config/langfuse-models.yaml)')]
final class PricesCommand
{
    public function __construct(
        private readonly LangfusePriceDeclarer $declarer,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!$this->declarer->isConfigured()) {
            $io->warning('No Langfuse URL or keys: no price source, nothing declared; the chat shows the tokens only.');

            return 0;
        }

        $result = $this->declarer->declare();
        $io->success(\sprintf(
            'Langfuse prices: %d model(s) declared%s, %d already there.',
            \count($result['created']),
            [] === $result['created'] ? '' : ' (' . implode(', ', $result['created']) . ')',
            \count($result['existing']),
        ));

        return 0;
    }
}
