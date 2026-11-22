<?php

declare(strict_types=1);

namespace Emagma\McpCoreBundle\Command;

use Emagma\McpCoreBundle\Authorization\Action;
use Emagma\McpCoreBundle\Authorization\Grants;
use Emagma\McpCoreBundle\Authorization\HandlerClass;
use Emagma\McpCoreBundle\Authorization\Permission;
use Emagma\McpCoreBundle\Authorization\RevealsPersonalData;
use Emagma\McpCoreBundle\Authorization\ToolPermissionResolver;
use Mcp\Capability\RegistryInterface;
use Mcp\Server;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `mcp:permissions`: the grants the tools require, and the tools no grant can reach.
 * Wired on the undecorated registry: the default deny-all provider would hide every tool from it.
 *
 * @internal
 */
#[AsCommand(
    name: 'mcp:permissions',
    description: 'List the MCP permissions declared by tools and the available grant strings',
)]
final class ListPermissionsCommand extends Command
{
    /**
     * The server is unused as a value: injecting it runs the builder, which fills the registry.
     */
    // @phpstan-ignore constructor.unusedParameter ($server is injected for its side effect)
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly ToolPermissionResolver $permissions,
        Server $server,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'format',
            null,
            InputOption::VALUE_REQUIRED,
            'Output format: "txt" for humans, "json" for tooling',
            'txt',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var array<string, array<string, list<string>>> $byResource `<domain>:<resource>` => action => tool names */
        $byResource = [];
        /** @var list<string> $undeclared */
        $undeclared = [];

        foreach ($this->registry->getTools()->references as $tool) {
            $declared = $this->permissions->resolve($this->registry->getTool($tool->name));
            // No domain is no grant that fits: as unauthorizable as no declaration.
            if ([] === $declared || [] !== array_filter($declared, static fn (Permission $p): bool => null === $p->domain)) {
                $undeclared[] = $tool->name;

                continue;
            }
            foreach ($declared as $permission) {
                $byResource[$permission->domain . ':' . $permission->resource][$permission->action->value][] = $tool->name;
            }
            // A permission of an argument, not of the tool: listed so the identity provider mints its role.
            $class = HandlerClass::of($this->registry->getTool($tool->name)->handler);
            if (null !== $class && [] !== (new \ReflectionClass($class))->getAttributes(RevealsPersonalData::class)) {
                $reveal = RevealsPersonalData::permission();
                $byResource[$reveal->domain . ':' . $reveal->resource][$reveal->action->value][] = $tool->name;
            }
            // A permission the tool checks itself to serve more (#[WidenedBy]): same reason.
            foreach (null === $class ? [] : $this->permissions->wideningOf($class) as $widening) {
                if (null !== $widening->domain) {
                    $byResource[$widening->domain . ':' . $widening->resource][$widening->action->value][] = $tool->name;
                }
            }
        }

        ksort($byResource);

        if ('json' === $input->getOption('format')) {
            return $this->renderJson($output, $byResource, $undeclared);
        }

        foreach ($byResource as $resource => $actions) {
            $io->section($resource);
            $rows = [];
            foreach (Action::cases() as $action) {
                foreach ($actions[$action->value] ?? [] as $toolName) {
                    $rows[] = [$action->value, $toolName];
                }
            }
            $io->table(['action', 'tool'], $rows);
            $io->text(array_map(
                static fn (Action $action): string => \sprintf('grant: mcp:%s:%s', $resource, $action->value),
                array_values(array_filter(
                    Action::cases(),
                    static fn (Action $action): bool => isset($actions[$action->value]),
                )),
            ));
        }

        $io->section('wildcards');
        $io->text(array_map(
            static fn (Action $action): string => \sprintf('grant: mcp:%1$s:%1$s:%2$s (every domain), mcp:<domain>:%1$s:%2$s (one domain)', Grants::WILDCARD, $action->value),
            Action::cases(),
        ));
        $io->text('Implications: write ⊃ read, delete ⊃ read; write and delete are independent; reveal (the `reveal` argument of a get_* tool) is implied by nothing.');

        if ([] !== $undeclared) {
            $io->warning(\sprintf(
                'Tools with NO #[Permission] declaration, or of a namespace `emagma_mcp_core.permission_domains` does not list (never authorizable under a real grants provider): %s',
                implode(', ', $undeclared),
            ));

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d resources, every registered tool declares its permissions.', \count($byResource)));

        return Command::SUCCESS;
    }

    /**
     * `wildcards` is a separate key on purpose: they are not minted as roles, and a drift check must not expect them.
     *
     * @param array<string, array<string, list<string>>> $byResource
     * @param list<string>                               $undeclared
     */
    private function renderJson(OutputInterface $output, array $byResource, array $undeclared): int
    {
        $grants = [];
        foreach ($byResource as $resource => $actions) {
            foreach (array_keys($actions) as $action) {
                $grants[] = \sprintf('mcp:%s:%s', $resource, $action);
            }
        }
        sort($grants);

        $output->writeln(json_encode([
            'grants' => $grants,
            'wildcards' => array_map(
                static fn (Action $action): string => \sprintf('mcp:%1$s:%1$s:%2$s', Grants::WILDCARD, $action->value),
                Action::cases(),
            ),
            'undeclared' => $undeclared,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

        return [] === $undeclared ? Command::SUCCESS : Command::FAILURE;
    }
}
