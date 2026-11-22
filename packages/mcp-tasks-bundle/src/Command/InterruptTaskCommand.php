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
 * An external orchestrator lost a step in flight: the task is suspended, never played again.
 *
 * @internal
 */
#[AsCommand(
    name: 'mcp:task:interrupted',
    description: 'Suspend a running task whose step was lost (reason "interrupted"). Exit code: 0 when the task is suspended, 1 otherwise',
)]
final class InterruptTaskCommand extends Command
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

        $this->lifecycle->interrupted($taskId);
        $status = $this->store->status($taskId);
        if (TaskStatus::Suspended === $status) {
            $io->writeln(\sprintf('Task %s is suspended.', $taskId));

            return self::SUCCESS;
        }
        $io->error(\sprintf('Task %s is not suspended: %s. Only a running task is interrupted.', $taskId, $status->value ?? 'unknown'));

        return self::FAILURE;
    }
}
