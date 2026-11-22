<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Command;

use Emagma\McpAuditBundle\Repository\AuditRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Meant for the integrator's cron: the server never deletes an entry by itself.
 *
 * @internal
 */
#[AsCommand(
    name: 'mcp:audit:purge',
    description: 'Delete the audit entries older than the configured retention',
)]
final class PurgeAuditCommand extends Command
{
    public function __construct(
        private readonly AuditRepositoryInterface $repository,
        private readonly string $retention,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'ISO 8601 duration overriding emagma_mcp_audit.retention (e.g. P90D)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be deleted, delete nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $duration = $input->getOption('older-than');
        $duration = \is_string($duration) && '' !== $duration ? $duration : $this->retention;

        try {
            $cutoff = (new \DateTimeImmutable())->sub(new \DateInterval($duration));
        } catch (\Exception) {
            $io->error(\sprintf('"%s" is not an ISO 8601 duration such as P1Y or P90D.', $duration));

            return self::INVALID;
        }

        if ($input->getOption('dry-run')) {
            $count = $this->repository->countBefore($cutoff);
            $io->note(\sprintf('%d audit %s older than %s (%s) would be deleted.', $count, 1 === $count ? 'entry' : 'entries', $cutoff->format(\DateTimeInterface::ATOM), $duration));

            return self::SUCCESS;
        }

        $count = $this->repository->purgeBefore($cutoff);
        $io->success(\sprintf('%d audit %s older than %s (%s) deleted. A younger revert of a deleted entry keeps its row with an empty "reverts".', $count, 1 === $count ? 'entry' : 'entries', $cutoff->format(\DateTimeInterface::ATOM), $duration));

        return self::SUCCESS;
    }
}
