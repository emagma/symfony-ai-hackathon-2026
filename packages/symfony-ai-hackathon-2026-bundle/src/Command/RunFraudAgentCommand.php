<?php

declare(strict_types=1);

namespace Emagma\SymfonyAiHackathon2026Bundle\Command;

use Emagma\McpAgentsBundle\AgentRunner;
use Emagma\SymfonyAiHackathon2026Bundle\FraudCheckerAgent\FraudAgent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs one pass of the fraud agent by hand, under the lock a pass of the worker takes.
 *
 * @internal
 */
#[AsCommand(
    name: 'hackathon:agent:fraud',
    description: 'Run the fraud agent once, now: the customer accounts created since its last run',
)]
final class RunFraudAgentCommand extends Command
{
    public function __construct(
        private readonly AgentRunner $runner,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // The agent's lock, as a pass of the worker takes it: never two passes at once.
        $summary = $this->runner->run(FraudAgent::AGENT);
        if (null === $summary) {
            $io->warning('A pass of the fraud agent is running: it reads the new accounts. Nothing was done.');

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%d account(s) analysed, %d reported (medium or high), %d alert(s) sent.',
            $summary['analysed'] ?? 0,
            $summary['reported'] ?? 0,
            $summary['alerted'] ?? 0,
        ));

        return Command::SUCCESS;
    }
}
