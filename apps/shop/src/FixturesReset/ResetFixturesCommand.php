<?php

declare(strict_types=1);

namespace App\FixturesReset;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Puts the shop's data back to its fixtures on the existing schema: what the
 * "Reset the demo data" admin page runs, and the last step of a full reset
 * after a schema rebuild.
 *
 * The `default` suite's orm_purger empties every ORM table before loading,
 * the MCP service account included, so the account is re-created after.
 * Messenger's table is not an ORM table: the purge keeps it, and the messages
 * still queued there point at entities that no longer exist.
 */
#[AsCommand(name: 'app:fixtures:reset', description: 'Reload the fixtures over the current data, then re-create the MCP service account')]
final class ResetFixturesCommand extends Command
{
    /** Another reset holds the lock (EX_TEMPFAIL). */
    public const ALREADY_RUNNING = 75;

    private const MESSENGER_TABLE = 'messenger_messages';

    public function __construct(
        private readonly Connection $connection,
        private readonly FixturesResetLock $lock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->lock->acquire()) {
            $io->warning('A fixtures reset is already running.');

            return self::ALREADY_RUNNING;
        }

        try {
            $io->section('Drop the queued Messenger messages');
            $io->writeln(sprintf('%d message(s) dropped.', $this->dropQueuedMessages()));

            foreach ([
                ['sylius:fixtures:load', ['suite' => 'default']],
                ['sylius-mcp:service-account', []],
                ['cache:pool:clear', ['pools' => ['cache.app']]],
            ] as [$name, $arguments]) {
                $io->section($name);
                $code = $this->runCommand($name, $arguments, $output);
                if (Command::SUCCESS !== $code) {
                    $io->error(sprintf('"%s" failed (exit code %d).', $name, $code));

                    return Command::FAILURE;
                }
            }
        } finally {
            $this->lock->release();
        }

        $io->success('The shop is back to its fixtures.');

        return Command::SUCCESS;
    }

    private function dropQueuedMessages(): int
    {
        if (!$this->connection->createSchemaManager()->tablesExist([self::MESSENGER_TABLE])) {
            return 0;
        }

        // delivered_at is set while a consumer handles the message: leave those.
        return (int) $this->connection->executeStatement(
            sprintf('DELETE FROM %s WHERE delivered_at IS NULL', self::MESSENGER_TABLE),
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function runCommand(string $name, array $arguments, OutputInterface $output): int
    {
        $application = $this->getApplication();
        if (null === $application) {
            throw new \LogicException('The command must run inside a console application.');
        }

        $input = new ArrayInput($arguments);
        $input->setInteractive(false);

        return $application->find($name)->run($input, $output);
    }
}
