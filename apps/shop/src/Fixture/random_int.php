<?php

declare(strict_types=1);

/*
 * Loaded only by DeterministicSeedListener, before the fixtures suite runs.
 *
 * Sylius' fixture factories draw some values with random_int() (the items and
 * quantities of an order, a review's status, an address's phone and company),
 * which no seed reaches: it reads the system CSPRNG. An unqualified call
 * resolves to a function of the caller's namespace first, so these shadow it
 * with mt_rand(), which mt_srand() seeds. Excluded from the service scan
 * (config/services.yaml): nothing else may define them.
 */

namespace Sylius\Bundle\CoreBundle\Fixture\Factory {
    if (!\function_exists(__NAMESPACE__ . '\random_int')) {
        function random_int(int $min, int $max): int
        {
            return \mt_rand($min, $max);
        }
    }
}

namespace Sylius\Bundle\CoreBundle\Fixture\OptionsResolver {
    if (!\function_exists(__NAMESPACE__ . '\random_int')) {
        function random_int(int $min, int $max): int
        {
            return \mt_rand($min, $max);
        }
    }
}
