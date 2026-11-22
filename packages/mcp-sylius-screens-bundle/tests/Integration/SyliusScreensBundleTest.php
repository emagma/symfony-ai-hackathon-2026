<?php

declare(strict_types=1);

namespace Emagma\McpSyliusScreensBundle\Tests\Integration;

use Emagma\McpScreensBundle\App\ScreenApp;
use Emagma\McpScreensBundle\ScreenRegistry;
use Emagma\McpSyliusScreensBundle\EmagmaMcpSyliusScreensBundle;
use Emagma\McpSyliusScreensBundle\Screen\AddressScreen;
use Emagma\McpSyliusScreensBundle\Screen\ProductVariantsScreen;
use Emagma\McpSyliusScreensBundle\Tests\Integration\Fixture\TestKernel;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

#[CoversClass(EmagmaMcpSyliusScreensBundle::class)]
final class SyliusScreensBundleTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testTheTwoScreensAreOnTheScreensRegistry(): void
    {
        self::bootKernel();

        $registry = self::getContainer()->get('test.screen_registry');
        self::assertInstanceOf(ScreenRegistry::class, $registry);
        self::assertInstanceOf(ProductVariantsScreen::class, $registry->get('product_variants'));
        self::assertInstanceOf(AddressScreen::class, $registry->get('address'));
    }

    public function testTheTemplateOfEachScreenIsServedUnderTheBundleNamespaceAndRenders(): void
    {
        self::bootKernel();

        $registry = self::getContainer()->get('test.screen_registry');
        self::assertInstanceOf(ScreenRegistry::class, $registry);
        $twig = self::getContainer()->get('test.twig');
        self::assertInstanceOf(Environment::class, $twig);

        $data = [
            'product_variants' => [
                'productCode' => 'CAP',
                'channelCode' => 'WEB',
                'channelCodes' => ['WEB'],
                'currency' => 'USD',
                'variants' => [['code' => 'CAP-S', 'name' => 'Cap S', 'price' => '99.05', 'tracked' => true, 'onHand' => 4, 'onHold' => 1]],
                'more' => false,
            ],
            'address' => [
                'subject' => 'Shipping address of order 000000025',
                'addressId' => '12',
                'locked' => null,
                'fields' => ['street' => ['label' => 'Street', 'value' => '1 rue de la Paix', 'required' => true]],
            ],
        ];
        $expected = ['product_variants' => 'Cap S', 'address' => '1 rue de la Paix'];

        foreach ($data as $name => $screenData) {
            $screen = $registry->get($name);
            self::assertNotNull($screen);
            self::assertStringStartsWith('@EmagmaMcpSyliusScreens/', $screen->template());
            self::assertTrue($twig->getLoader()->exists($screen->template()), $name . ': the template is not found');

            foreach (['display', 'form'] as $mode) {
                $html = $twig->render(ScreenApp::FRAGMENT, [
                    'screen' => $name,
                    'params' => [],
                    'mode' => $mode,
                    'can_edit' => true,
                    'data' => $screenData,
                    'errors' => [],
                    'message' => null,
                    'typed' => [],
                    'template' => $screen->template(),
                ]);

                self::assertStringContainsString($expected[$name], $html, $name . ' in ' . $mode . ' mode');
            }
        }
    }
}
