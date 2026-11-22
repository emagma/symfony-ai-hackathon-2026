<?php

declare(strict_types=1);

namespace Emagma\SyliusMcpEventPlugin\EventListener;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Emagma\SyliusMcpEventPlugin\Relay\ShopEventRelay;
use Sylius\Component\Core\Model\CustomerInterface;

/**
 * A Doctrine listener rather than a Sylius resource event: it sees every way a customer comes to exist
 * (registration, guest checkout, admin, API, import), on every Sylius line.
 *
 * @internal
 */
final readonly class CustomerCreatedListener
{
    public const string EVENT = 'customer.created';

    public function __construct(
        private ShopEventRelay $relay,
    ) {
    }

    /**
     * @param LifecycleEventArgs<\Doctrine\Persistence\ObjectManager> $args
     */
    public function postPersist(LifecycleEventArgs $args): void
    {
        $customer = $args->getObject();
        if (!$customer instanceof CustomerInterface) {
            return;
        }
        $id = $customer->getId();
        if (!\is_int($id) && !\is_string($id)) {
            return;
        }

        // No email, no name: the server reads the customer itself, pseudonymised where its rules say so.
        $this->relay->relay(self::EVENT, [
            'id' => (string) $id,
            'createdAt' => ($customer->getCreatedAt() ?? new \DateTimeImmutable())->format(\DATE_ATOM),
        ]);
    }
}
