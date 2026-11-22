<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Command;

use Emagma\McpTasksBundle\Orchestration\TaskLifecycle;
use Emagma\McpTasksBundle\Store\TaskStoreInterface;
use Emagma\McpTasksBundle\TaskStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * An external orchestrator played the last step: the task is over, its owner is told.
 *
 * @internal
 */
#[AsCommand(
    name: 'mcp:task:finish',
    description: 'End a task whose last step is done. Exit code: 0 when the task is done, 1 otherwise (its status is printed)',
)]
final class FinishTaskCommand extends Command
{
    public function __construct(
        private readonly TaskLifecycle $lifecycle,
        private readonly TaskStoreInterface $store,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('taskId', InputArgument::REQUIRED, 'The id of the task');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $taskId = $input->getArgument('taskId');
        if (!\is_string($taskId) || '' === $taskId) {
            $io->error('Usage: ' . $this->getName() . ' <taskId>');

            return self::INVALID;
        }

        $this->lifecycle->finished($taskId);
        $status = $this->store->status($taskId);
        if (TaskStatus::Done === $status) {
            $io->writeln(\sprintf('Task %s is done.', $taskId));

            return self::SUCCESS;
        }
        $io->error(\sprintf('Task %s is not done: %s. Only a running task whose last step is over ends.', $taskId, $status->value ?? 'unknown'));

        return self::FAILURE;
    }
}
