<?php

declare(strict_types=1);

namespace Emagma\McpAuditBundle\Command;

use Emagma\McpAuditBundle\Entity\AuditEntry;
use Emagma\McpAuditBundle\Forget\PersonalDataEraser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The command line of `forget_audit_personal_data`: same eraser, same trace, no actor.
 *
 * @internal
 */
#[AsCommand(
    name: 'mcp:audit:forget-personal-data',
    description: 'Erase every value the audit recorded about a subject (a customer, …): the rows stay, locked',
)]
final class ForgetPersonalDataCommand extends Command
{
    public function __construct(
        private readonly PersonalDataEraser $eraser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'The target type of the subject, as the audit records it: "customer"')
            ->addArgument('id', InputArgument::REQUIRED, 'Its id, as the audit records it (the target code)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the entries that would be forgotten, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $type = $input->getArgument('type');
        $id = $input->getArgument('id');
        if (!\is_string($type) || '' === trim($type) || !\is_string($id) || '' === trim($id)) {
            $io->error('Usage: mcp:audit:forget-personal-data <type> <id>, e.g. mcp:audit:forget-personal-data customer 12');

            return self::INVALID;
        }
        $type = trim($type);
        $id = trim($id);

        $entries = $this->eraser->concerning($type, $id);
        if ([] === $entries) {
            $io->note(\sprintf('No audit entry concerns %s %s.', $type, $id));

            return self::SUCCESS;
        }

        $io->table(
            ['id', 'created at', 'tool', 'target', 'forgotten'],
            array_map(static fn (AuditEntry $entry): array => [
                $entry->getId(),
                $entry->getCreatedAt()->format(\DateTimeInterface::ATOM),
                $entry->getTool(),
                $entry->getTargetType() . ' ' . $entry->getTargetCode(),
                $entry->getForgottenAt()?->format(\DateTimeInterface::ATOM) ?? '',
            ], $entries),
        );

        $pending = \count(array_filter($entries, static fn (AuditEntry $entry): bool => !$entry->isForgotten()));
        if ($input->getOption('dry-run')) {
            $io->note(\sprintf('%d %s would be forgotten, %d already %s.', $pending, self::entries($pending), \count($entries) - $pending, 1 === \count($entries) - $pending ? 'is' : 'are'));

            return self::SUCCESS;
        }

        $done = $this->eraser->forget($type, $id, $entries);
        if (null === $done['trace']) {
            $io->note(\sprintf('Every audit entry concerning %s %s was already forgotten.', $type, $id));

            return self::SUCCESS;
        }

        $count = \count($done['forgotten']);
        $io->success(\sprintf('%d %s forgotten for %s %s, traced by entry #%d. The rows remain, locked: their values are gone, and none of them can be reverted anymore.', $count, self::entries($count), $type, $id, $done['trace']->getId() ?? 0));

        return self::SUCCESS;
    }

    private static function entries(int $count): string
    {
        return 1 === $count ? 'entry' : 'entries';
    }
}
