<?php

declare(strict_types=1);

namespace App\Fixture;

use Sylius\Bundle\FixturesBundle\Listener\AbstractListener;
use Sylius\Bundle\FixturesBundle\Listener\BeforeSuiteListenerInterface;
use Sylius\Bundle\FixturesBundle\Listener\SuiteEvent;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

/**
 * Makes a fixtures run reproducible: the same seed loads the same catalog,
 * prices, stock, customers, orders and reviews on every run, so the demo
 * scenarios find the same data after each reset.
 *
 * Faker, array_rand() and shuffle() draw from the Mersenne Twister that
 * mt_srand() seeds; the factories' random_int() calls are routed to it by
 * random_int.php. Dates stay relative to the run ("an order 3 days ago"),
 * and what never reaches the data (image file names, tokens, password
 * hashes) still varies.
 */
final class DeterministicSeedListener extends AbstractListener implements BeforeSuiteListenerInterface
{
    public function getName(): string
    {
        return 'deterministic_seed';
    }

    /**
     * @param array{seed: int} $options
     */
    public function beforeSuite(SuiteEvent $suiteEvent, array $options): void
    {
        require_once __DIR__ . '/random_int.php';

        mt_srand($options['seed']);
    }

    protected function configureOptionsNode(ArrayNodeDefinition $optionsNode): void
    {
        $optionsNode
            ->children()
                ->integerNode('seed')->defaultValue(20261020)->end()
        ;
    }
}
