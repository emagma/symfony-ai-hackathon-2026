<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Command;

use Emagma\McpTasksBundle\Orchestration\StepExecutor;
use Emagma\McpTasksBundle\Orchestration\StepOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The entry of an external orchestrator: it sequences, the step runs here, with the owner's rights.
 *
 * @internal
 */
#[AsCommand(
    name: 'mcp:task:step',
    description: 'Play one step of a task, as its owner. Exit code: 0 done, 1 failed (the task is suspended), 2 skipped (play nothing more), 3 wrong arguments',
)]
final class PlayStepCommand extends Command
{
    public const int SKIPPED = 2;

    public const int WRONG_ARGUMENTS = 3;

    public function __construct(
        private readonly StepExecutor $executor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('taskId', InputArgument::REQUIRED, 'The id of the task')
            ->addArgument('step', InputArgument::REQUIRED, 'The index of the step, from 0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $taskId = $input->getArgument('taskId');
        $step = $input->getArgument('step');
        if (!\is_string($taskId) || '' === $taskId || !\is_string($step) || 1 !== preg_match('/^\d{1,4}$/', $step)) {
            $io->error('Usage: mcp:task:step <taskId> <step>, the step from 0.');

            return self::WRONG_ARGUMENTS;
        }

        $outcome = $this->executor->execute($taskId, (int) $step);
        $io->writeln(\sprintf('Task %s, step %d: %s.', $taskId, (int) $step, strtolower($outcome->name)));

        return match ($outcome) {
            StepOutcome::Done => self::SUCCESS,
            StepOutcome::Failed => self::FAILURE,
            StepOutcome::Skipped => self::SKIPPED,
        };
    }
}
