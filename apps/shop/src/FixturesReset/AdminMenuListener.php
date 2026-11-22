<?php

declare(strict_types=1);

namespace App\FixturesReset;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * "Reset the demo data", last entry of the admin's Configuration menu.
 */
#[AsEventListener(event: 'sylius.menu.admin.main')]
final class AdminMenuListener
{
    public function __construct(
        #[Autowire('%env(bool:FIXTURES_RESET_ENABLED)%')]
        private readonly bool $enabled,
    ) {
    }

    public function __invoke(MenuBuilderEvent $event): void
    {
        $configuration = $event->getMenu()->getChild('configuration');
        if (!$this->enabled || null === $configuration) {
            return;
        }

        $configuration
            ->addChild('fixtures_reset', [
                'route' => 'app_admin_fixtures_reset',
                'extras' => ['routes' => [['route' => 'app_admin_fixtures_reset_run']]],
            ])
            ->setLabel('app.ui.fixtures_reset.menu')
            ->setLabelAttribute('icon', 'tabler:restore')
        ;
    }
}
