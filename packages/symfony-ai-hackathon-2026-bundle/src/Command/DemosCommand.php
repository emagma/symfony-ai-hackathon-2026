<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Command;

use Emagma\SymfonyAiHackathon2026Bundle\DemoRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows the tour as a table, or one demo whole.
 *
 * @internal
 */
#[AsCommand(
    name: 'hackathon:demos',
    description: 'The demos of the hackathon tour, or the steps of one of them',
)]
final class DemosCommand extends Command
{
    public function __construct(
        private readonly DemoRepository $demos,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::OPTIONAL, 'The demo to show whole; without it, the list');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = $input->getArgument('name');

        if (!\is_string($name)) {
            $rows = [];
            foreach ($this->demos->all() as $demo) {
                $rows[] = [$demo->position, $demo->name, $demo->title, $demo->user];
            }
            $io->table(['#', 'Name', 'Demo', 'As'], $rows);
            $io->text('One demo whole: hackathon:demos <name>. In the chat: "Guide me through the demos".');

            return Command::SUCCESS;
        }

        $demo = $this->demos->get($name);
        if (null === $demo) {
            $io->error(\sprintf('There is no demo "%s".', $name));

            return Command::FAILURE;
        }

        $io->title(\sprintf('%d. %s', $demo->position, $demo->title));
        $io->text('Logged in the chat as: ' . $demo->user);
        if ([] !== $demo->prompts) {
            $io->section('Type in the chat');
            $io->listing($demo->prompts);
        }
        $io->section('Guide');
        $io->writeln($demo->guide);
        $io->section('Symfony AI');
        $io->listing($demo->symfonyAi);
        $io->section('Code');
        $io->listing($demo->code);

        return Command::SUCCESS;
    }
}
