<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\AdminApi\Command;

use Emagma\McpSyliusBundle\AdminApi\CapabilitiesReader;
use Emagma\McpSyliusBundle\AdminApi\Gate\ShopExtensions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Active here but absent from the shop is an error (its tools would fail on a 404); published by the
 * shop but inactive here is a warning, the normal state between installing and activating.
 *
 * @internal
 */
#[AsCommand(
    name: 'mcp:shop-extensions:check',
    description: 'Compare MCP_SHOP_EXTENSIONS with the extensions the shop publishes',
)]
final class CheckShopExtensionsCommand extends Command
{
    public const string CAPABILITIES_PATH = CapabilitiesReader::PATH;

    public function __construct(
        private readonly CapabilitiesReader $capabilities,
        private readonly ShopExtensions $extensions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $active = $this->extensions->active();

        try {
            $published = $this->capabilities->read()['extensions'];
        } catch (HttpExceptionInterface $exception) {
            $status = $exception->getResponse()->getStatusCode();
            if (404 !== $status) {
                $io->error(\sprintf('GET %s answered %d: %s', self::CAPABILITIES_PATH, $status, $exception->getMessage()));

                return Command::FAILURE;
            }
            if ([] === $active) {
                $io->success('No shop extension is active, and the shop publishes none (its plugin has no capabilities endpoint).');

                return Command::SUCCESS;
            }
            $io->error(\sprintf(
                'MCP_SHOP_EXTENSIONS activates %s, but the shop has no capabilities endpoint (plugin too old): its tools would fail.',
                implode(', ', $active),
            ));

            return Command::FAILURE;
        } catch (ExceptionInterface|\JsonException|\RuntimeException $exception) {
            $io->error(\sprintf('Could not read the shop\'s extensions (GET %s): %s', self::CAPABILITIES_PATH, $exception->getMessage()));

            return Command::FAILURE;
        }

        $missing = array_values(array_filter($active, static fn (string $name): bool => !\array_key_exists($name, $published)));
        $inactive = array_values(array_filter(array_keys($published), fn (string $name): bool => !$this->extensions->isActive($name)));

        foreach ($inactive as $name) {
            $io->warning(\sprintf(
                'The shop publishes the extension "%s"%s, which MCP_SHOP_EXTENSIONS does not activate: its tools stay hidden.',
                $name,
                '' === $published[$name] ? '' : ' (' . $published[$name] . ')',
            ));
        }
        if ([] !== $missing) {
            $io->error(\sprintf(
                'MCP_SHOP_EXTENSIONS activates %s, which the shop does not publish: install the extension in the shop or remove it from MCP_SHOP_EXTENSIONS.',
                implode(', ', $missing),
            ));

            return Command::FAILURE;
        }

        $io->success([] === $active
            ? 'No shop extension is active on the MCP server.'
            : \sprintf('Shop extensions active and published by the shop: %s.', implode(', ', $active)));

        return Command::SUCCESS;
    }
}
