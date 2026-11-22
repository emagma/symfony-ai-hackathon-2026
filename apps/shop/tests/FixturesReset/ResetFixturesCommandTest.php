<?php

declare(strict_types=1);

namespace App\Tests\FixturesReset;

use App\FixturesReset\FixturesResetLock;
use App\FixturesReset\ResetFixturesCommand;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetFixturesCommandTest extends TestCase
{
    private string $lockPath;

    /** @var \ArrayObject<int, array{string, array<string, mixed>, bool}> */
    private \ArrayObject $calls;

    protected function setUp(): void
    {
        $this->lockPath = sys_get_temp_dir() . '/fixtures-reset-test-' . bin2hex(random_bytes(4)) . '.lock';
        $this->calls = new \ArrayObject();
    }

    protected function tearDown(): void
    {
        @unlink($this->lockPath);
    }

    public function testRunsTheSequenceAfterDroppingTheQueuedMessages(): void
    {
        $connection = $this->connection(tableExists: true);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with('DELETE FROM messenger_messages WHERE delivered_at IS NULL')
            ->willReturn(3);

        $tester = $this->tester($connection);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame([
            ['sylius:fixtures:load', ['suite' => 'default'], false],
            ['sylius-mcp:service-account', [], false],
            ['cache:pool:clear', ['pools' => ['cache.app']], false],
        ], $this->calls->getArrayCopy());
        self::assertStringContainsString('3 message(s) dropped.', $tester->getDisplay());
        self::assertFalse((new FixturesResetLock($this->lockPath))->isHeldElsewhere(), 'The lock is released.');
    }

    public function testNoMessengerTableYet(): void
    {
        $connection = $this->connection(tableExists: false);
        $connection->expects(self::never())->method('executeStatement');

        self::assertSame(Command::SUCCESS, $this->tester($connection)->execute([]));
        self::assertCount(3, $this->calls);
    }

    public function testStopsAtTheFirstFailingStepAndReleasesTheLock(): void
    {
        $tester = $this->tester($this->connection(tableExists: false), failing: 'sylius-mcp:service-account');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame(
            ['sylius:fixtures:load', 'sylius-mcp:service-account'],
            array_column($this->calls->getArrayCopy(), 0),
        );
        self::assertFalse((new FixturesResetLock($this->lockPath))->isHeldElsewhere());
    }

    public function testRefusesWhileAnotherResetHoldsTheLock(): void
    {
        $other = new FixturesResetLock($this->lockPath);
        self::assertTrue($other->acquire());

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('createSchemaManager');

        self::assertSame(ResetFixturesCommand::ALREADY_RUNNING, $this->tester($connection)->execute([]));
        self::assertCount(0, $this->calls);

        $other->release();
    }

    /**
     * @return Connection&MockObject
     */
    private function connection(bool $tableExists): Connection
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('tablesExist')->with(['messenger_messages'])->willReturn($tableExists);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        return $connection;
    }

    private function tester(Connection $connection, ?string $failing = null): CommandTester
    {
        $application = new Application();
        $application->setAutoExit(false);
        $application->addCommand(new RecordingCommand('sylius:fixtures:load', $this->calls, $failing, 'suite'));
        $application->addCommand(new RecordingCommand('sylius-mcp:service-account', $this->calls, $failing));
        $application->addCommand(new RecordingCommand('cache:pool:clear', $this->calls, $failing, 'pools', InputArgument::IS_ARRAY));

        $command = new ResetFixturesCommand($connection, new FixturesResetLock($this->lockPath));
        $application->addCommand($command);

        return new CommandTester($command);
    }
}

/**
 * Stands in for a sub-command: records its arguments, fails on demand.
 */
final class RecordingCommand extends Command
{
    /**
     * @param \ArrayObject<int, array{string, array<string, mixed>, bool}> $calls
     */
    public function __construct(
        string $name,
        private readonly \ArrayObject $calls,
        private readonly ?string $failing,
        ?string $argument = null,
        int $mode = InputArgument::OPTIONAL,
    ) {
        parent::__construct($name);

        if (null !== $argument) {
            $this->addArgument($argument, $mode);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $this->getName();
        // The application's own `command` argument is not one the caller passes.
        $arguments = array_diff_key($input->getArguments(), ['command' => true]);
        $this->calls->append([
            $name,
            array_filter($arguments, static fn (mixed $value): bool => null !== $value && [] !== $value),
            $input->isInteractive(),
        ]);

        return $name === $this->failing ? Command::FAILURE : Command::SUCCESS;
    }
}
