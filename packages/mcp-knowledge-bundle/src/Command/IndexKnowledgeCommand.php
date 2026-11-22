<?php

declare(strict_types=1);

namespace Emagma\McpKnowledgeBundle\Command;

use Emagma\McpKnowledgeBundle\Index\KnowledgeIndex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mcp:knowledge:index',
    description: 'Index the procedures written or changed since the last run, forget the deleted ones',
)]
/**
 * Brings the index in step with the procedures by hand: what the search does on its own, or a full rebuild.
 *
 * @internal
 */
final class IndexKnowledgeCommand extends Command
{
    public function __construct(
        private readonly KnowledgeIndex $index,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('rebuild', null, InputOption::VALUE_NONE, 'Empty the index and read every procedure again');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $summary = $this->index->sync((bool) $input->getOption('rebuild'));

        (new SymfonyStyle($input, $output))->success(\sprintf(
            '%d file(s) indexed, %d removed, %d unchanged: %d passage(s), searched by %s.',
            $summary['indexed'],
            $summary['removed'],
            $summary['unchanged'],
            $this->index->count(),
            $this->index->mode(),
        ));

        return Command::SUCCESS;
    }
}
