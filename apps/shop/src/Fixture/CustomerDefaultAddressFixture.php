<?php

declare(strict_types=1);

namespace App\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A default address for every customer who can have one: the default suite
 * fills the address books and places orders, but leaves every customer
 * without a default address.
 *
 * - a customer with an address book gets its first address as default;
 * - a customer who only ordered gets a copy of the shipping address of their
 *   first order (a copy: the address of an order is the order's own).
 *
 * Runs last and reads in id order, so every reset gives the same customers
 * the same default address.
 */
final class CustomerDefaultAddressFixture extends AbstractFixture
{
    /**
     * @param RepositoryInterface<CustomerInterface> $customerRepository
     * @param RepositoryInterface<OrderInterface>    $orderRepository
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'sylius.repository.customer')]
        private readonly RepositoryInterface $customerRepository,
        #[Autowire(service: 'sylius.repository.order')]
        private readonly RepositoryInterface $orderRepository,
    ) {
    }

    public function getName(): string
    {
        return 'mcp_customer_default_addresses';
    }

    public function load(array $options): void
    {
        foreach ($this->customerRepository->findBy([], ['id' => 'ASC']) as $customer) {
            if (null !== $customer->getDefaultAddress()) {
                continue;
            }

            $address = $this->firstOfAddressBook($customer) ?? $this->copyOfFirstShippingAddress($customer);
            if (null !== $address) {
                $customer->setDefaultAddress($address);
            }
        }

        $this->entityManager->flush();
    }

    private function firstOfAddressBook(CustomerInterface $customer): ?AddressInterface
    {
        $first = null;
        foreach ($customer->getAddresses() as $address) {
            if (null === $first || $address->getId() < $first->getId()) {
                $first = $address;
            }
        }

        return $first;
    }

    private function copyOfFirstShippingAddress(CustomerInterface $customer): ?AddressInterface
    {
        foreach ($this->orderRepository->findBy(['customer' => $customer], ['id' => 'ASC']) as $order) {
            $shipping = $order->getShippingAddress();
            if (null === $shipping) {
                continue;
            }

            $copy = clone $shipping;
            $customer->addAddress($copy);
            $this->entityManager->persist($copy);

            return $copy;
        }

        return null;
    }
}
