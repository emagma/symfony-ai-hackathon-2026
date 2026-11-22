<?php

declare(strict_types=1);

namespace Emagma\McpSyliusBundle\Tool\Payment;

use Emagma\McpSyliusBundle\Output\Payment\PaymentOutput;

/**
 * Deliberately NOT divided into a major unit: the payload does not carry the
 * currency's subunit (JPY has none), so any division would be a guess.
 *
 * @internal
 */
trait PaymentAmountTrait
{
    private static function describeAmount(PaymentOutput $payment): string
    {
        if (null === $payment->amount) {
            return 'unknown amount';
        }

        return $payment->amount . ' ' . ($payment->currencyCode ?? '') . ' (minor units)';
    }
}
