<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Command;

use Emagma\McpSyliusBundle\AdminApi\CapabilitiesReader;
use Emagma\McpSyliusBundle\AdminApi\Line\SyliusLines;
use Emagma\McpSyliusBundle\AdminApi\SyliusVersion;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * The version is configured, not discovered: a shop upgraded without the configuration would get the
 * wrong routes and tools in silence. Another minor is an error, another patch only is named.
 *
 * @internal
 */
#[AsCommand(
    name: 'mcp:sylius-version:check',
    description: 'Compare MCP_SYLIUS_VERSION with the Sylius version the shop publishes',
)]
final class CheckSyliusVersionCommand extends Command
{
    public function __construct(
        private readonly CapabilitiesReader $capabilities,
        private readonly SyliusVersion $version,
        private readonly SyliusLines $lines,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $published = $this->capabilities->read()['sylius'];
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getResponse()->getStatusCode();
            $io->error(404 === $status
                ? \sprintf('The shop has no capabilities endpoint (GET %s): its plugin is too old to tell its Sylius version.', CapabilitiesReader::PATH)
                : \sprintf('GET %s answered %d: %s', CapabilitiesReader::PATH, $status, $exception->getMessage()));

            return Command::FAILURE;
        } catch (ExceptionInterface|\JsonException|\RuntimeException $exception) {
            $io->error(\sprintf('Could not read the shop\'s Sylius version (GET %s): %s', CapabilitiesReader::PATH, $exception->getMessage()));

            return Command::FAILURE;
        }

        if (null === $published) {
            $io->warning(\sprintf('The shop\'s plugin does not publish its Sylius version: MCP_SYLIUS_VERSION (%s) is not checked. Update the plugin.', $this->version));

            return Command::SUCCESS;
        }

        try {
            // A pre-release (2.3.0-RC.1) is its version for this purpose.
            $shop = new SyliusVersion(1 === preg_match('/^\d+\.\d+(?:\.\d+)?/', $published, $numeric) ? $numeric[0] : $published);
        } catch (\InvalidArgumentException) {
            $shop = null;
        }
        if (null === $shop || !$this->lines->supports($shop)) {
            $io->error(\sprintf('The shop runs Sylius %s, which this MCP server does not support (supported lines: %s).', $published, implode(', ', $this->lines->versions())));

            return Command::FAILURE;
        }

        if ([$shop->major, $shop->minor] !== [$this->version->major, $this->version->minor]) {
            $io->error(\sprintf('MCP_SYLIUS_VERSION is %s, but the shop runs Sylius %s: set MCP_SYLIUS_VERSION=%s.', $this->version, $shop, $shop));

            return Command::FAILURE;
        }

        $io->success($shop->patch === $this->version->patch
            ? \sprintf('The shop runs Sylius %s, as MCP_SYLIUS_VERSION says.', $shop)
            : \sprintf('The shop runs Sylius %s; MCP_SYLIUS_VERSION says %s (same line, another patch).', $shop, $this->version));

        return Command::SUCCESS;
    }
}
