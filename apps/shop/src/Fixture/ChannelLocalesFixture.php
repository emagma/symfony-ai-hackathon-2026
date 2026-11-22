<?php

declare(strict_types=1);

namespace App\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webmozart\Assert\Assert;

/**
 * Locales added to a channel that already exists, the core's `channel`
 * fixture only creating channels: a locale created after the products (none
 * is translated into it) is offered on the storefront all the same.
 *
 * Draws nothing at random: appended after the rest of the suite, it leaves
 * every seeded data as it was.
 */
final class ChannelLocalesFixture extends AbstractFixture
{
    /**
     * @param RepositoryInterface<ChannelInterface> $channelRepository
     * @param RepositoryInterface<LocaleInterface>  $localeRepository
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'sylius.repository.channel')]
        private readonly RepositoryInterface $channelRepository,
        #[Autowire(service: 'sylius.repository.locale')]
        private readonly RepositoryInterface $localeRepository,
    ) {
    }

    public function getName(): string
    {
        return 'mcp_channel_locales';
    }

    /**
     * @param array{channel: string, locales: list<string>} $options
     */
    public function load(array $options): void
    {
        $channel = $this->channelRepository->findOneBy(['code' => $options['channel']]);
        Assert::isInstanceOf($channel, ChannelInterface::class, sprintf('Channel "%s" is not in the fixtures.', $options['channel']));

        foreach ($options['locales'] as $code) {
            $locale = $this->localeRepository->findOneBy(['code' => $code]);
            Assert::isInstanceOf($locale, LocaleInterface::class, sprintf('Locale "%s" is not in the fixtures.', $code));
            $channel->addLocale($locale);
        }

        $this->entityManager->flush();
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->scalarNode('channel')->isRequired()->cannotBeEmpty()->end()
                ->arrayNode('locales')->scalarPrototype()->end()->end()
        ;
    }
}
