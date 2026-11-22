<?php

declare(strict_types=1);

namespace App\Tests\FixturesReset;

use App\FixturesReset\AdminMenuListener;
use Knp\Menu\ItemInterface;
use Knp\Menu\MenuFactory;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListenerTest extends TestCase
{
    private MenuFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new MenuFactory();
    }

    public function testAddsTheEntryAtTheEndOfTheConfigurationMenu(): void
    {
        $menu = $this->menu();

        (new AdminMenuListener(true))(new MenuBuilderEvent($this->factory, $menu));

        $configuration = $menu->getChild('configuration');
        self::assertNotNull($configuration);
        self::assertSame(['channels', 'fixtures_reset'], array_keys($configuration->getChildren()));

        $entry = $configuration->getChild('fixtures_reset');
        self::assertNotNull($entry);
        self::assertSame('app.ui.fixtures_reset.menu', $entry->getLabel());
        self::assertSame('tabler:restore', $entry->getLabelAttribute('icon'));
    }

    public function testNothingWhenDisabled(): void
    {
        $menu = $this->menu();

        (new AdminMenuListener(false))(new MenuBuilderEvent($this->factory, $menu));

        self::assertSame(['channels'], array_keys($menu->getChild('configuration')?->getChildren() ?? []));
    }

    public function testNothingWithoutAConfigurationMenu(): void
    {
        $menu = $this->factory->createItem('root');

        (new AdminMenuListener(true))(new MenuBuilderEvent($this->factory, $menu));

        self::assertSame([], $menu->getChildren());
    }

    private function menu(): ItemInterface
    {
        $menu = $this->factory->createItem('root');
        $menu->addChild('configuration')->addChild('channels');

        return $menu;
    }
}
