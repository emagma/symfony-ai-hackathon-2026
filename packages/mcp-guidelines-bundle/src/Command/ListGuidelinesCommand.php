<?php

declare(strict_types=1);

namespace Emagma\McpGuidelinesBundle\Command;

use Emagma\McpGuidelinesBundle\GuidelineResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints the guidelines and the intents as configured.
 *
 * @internal
 */
#[AsCommand(
    name: 'mcp:guidelines',
    description: 'The guidelines of the business, where each comes from, and the intents that give them to the model',
)]
final class ListGuidelinesCommand extends Command
{
    public function __construct(
        private readonly GuidelineResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $guidelines = $this->resolver->all();

        $io->section('Guidelines');
        $rows = [];
        foreach ($this->resolver->resources() as $resource) {
            $guideline = $guidelines[$resource['name']] ?? null;
            $rows[] = [$resource['name'], $resource['loader'], $resource['resource'], null === $guideline ? 'cannot be read' : (string) mb_strlen($guideline->content)];
        }
        $io->table(['name', 'loader', 'resource', 'characters'], $rows);

        $io->section('Intents (listed to the model when a conversation starts)');
        $rows = [];
        foreach ($this->resolver->intents() as $intent => $definition) {
            $rows[] = [$intent, $definition['description'], implode(', ', $definition['guidelines'])];
        }
        if ([] === $rows) {
            $io->text('None: the model is not told to ask for guidelines.');
        } else {
            $io->table(['intent', 'description', 'guidelines, in order'], $rows);
        }

        return Command::SUCCESS;
    }
}
