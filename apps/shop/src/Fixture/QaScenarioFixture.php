<?php

declare(strict_types=1);

namespace App\Fixture;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Bundle\FixturesBundle\Fixture\AbstractFixture;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webmozart\Assert\Assert;

/**
 * The data the QA test scenarios rely on (apps/mcp-server/tests/Qa, played
 * by `castor mcp:qa`), laid over the default suite. Everything is explicit:
 * the scenarios read the same stock, customers and orders after every reset.
 *
 * - stock tracking on the women's t-shirts and on Stellar Drift T-Shirt, with
 *   a fixed stock per size for the latter (scenario 1);
 * - 5 paid, not yet shipped orders of Stellar Drift T-Shirt in size M from 4
 *   customers, one of whom ordered twice (scenario 1: one email per customer);
 * - 3 products of the `qa_products` fixture without a short description
 *   (scenario 3; the product fixture refuses an empty one);
 * - 2 dresses translated in en_US only (scenario 7);
 * - 2 women's jeans out of stock (scenario 8).
 */
final class QaScenarioFixture extends AbstractFixture
{
    private const string CHANNEL = 'FASHION_WEB';

    private const string STELLAR_DRIFT = 'Stellar_Drift_T_Shirt';

    /**
     * Stock per size of Stellar Drift T-Shirt, before the orders below take
     * theirs: the M ends at 2 once its 9 are sold.
     */
    private const array STELLAR_DRIFT_STOCK = [
        't_shirt_size_s' => 3,
        't_shirt_size_m' => 11,
        't_shirt_size_l' => 2,
        't_shirt_size_xl' => 8,
        't_shirt_size_xxl' => 6,
    ];

    private const array WITHOUT_SHORT_DESCRIPTION = ['Aurora_Breeze_T_Shirt', 'Nebula_Glow_T_Shirt', 'Orbit_Pulse_T_Shirt'];

    private const array EN_US_ONLY = ['Sunshine_Strappy_Delight', 'Boho_Beach_Breeze'];

    private const array OUT_OF_STOCK = ['Sunlit_Reverie_Jeans', 'Seaside_Tranquility_Jeans'];

    /** email => [first name, last name, street, city, postcode] */
    private const array CUSTOMERS = [
        'emma.laurent@example.com' => ['Emma', 'Laurent', '12 Market Street', 'San Francisco', '94105'],
        'lucas.martin@example.com' => ['Lucas', 'Martin', '480 Lake Shore Drive', 'Chicago', '60611'],
        'chloe.bernard@example.com' => ['Chloé', 'Bernard', '75 Ocean Avenue', 'Miami', '33139'],
        'hugo.petit@example.com' => ['Hugo', 'Petit', '221 Pine Street', 'Seattle', '98101'],
    ];

    /** [customer email, days ago, size option value, quantity] */
    private const array ORDERS = [
        ['emma.laurent@example.com', 4, 't_shirt_size_m', 2],
        ['lucas.martin@example.com', 3, 't_shirt_size_m', 2],
        ['chloe.bernard@example.com', 3, 't_shirt_size_m', 2],
        ['hugo.petit@example.com', 2, 't_shirt_size_m', 2],
        ['emma.laurent@example.com', 1, 't_shirt_size_m', 1],
    ];

    /**
     * @param RepositoryInterface<ProductInterface>        $productRepository
     * @param RepositoryInterface<TaxonInterface>          $taxonRepository
     * @param RepositoryInterface<ChannelInterface>        $channelRepository
     * @param RepositoryInterface<CustomerInterface>       $customerRepository
     * @param RepositoryInterface<PaymentMethodInterface>  $paymentMethodRepository
     * @param FactoryInterface<CustomerInterface>          $customerFactory
     * @param FactoryInterface<OrderInterface>             $orderFactory
     * @param FactoryInterface<OrderItemInterface>         $orderItemFactory
     * @param FactoryInterface<AddressInterface>           $addressFactory
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        #[Autowire(service: 'sylius.repository.product')]
        private readonly RepositoryInterface $productRepository,
        #[Autowire(service: 'sylius.repository.taxon')]
        private readonly RepositoryInterface $taxonRepository,
        #[Autowire(service: 'sylius.repository.channel')]
        private readonly RepositoryInterface $channelRepository,
        #[Autowire(service: 'sylius.repository.customer')]
        private readonly RepositoryInterface $customerRepository,
        #[Autowire(service: 'sylius.repository.payment_method')]
        private readonly RepositoryInterface $paymentMethodRepository,
        #[Autowire(service: 'sylius.factory.customer')]
        private readonly FactoryInterface $customerFactory,
        #[Autowire(service: 'sylius.factory.order')]
        private readonly FactoryInterface $orderFactory,
        #[Autowire(service: 'sylius.factory.order_item')]
        private readonly FactoryInterface $orderItemFactory,
        #[Autowire(service: 'sylius.factory.address')]
        private readonly FactoryInterface $addressFactory,
        #[Autowire(service: 'sylius.modifier.order_item_quantity')]
        private readonly OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        #[Autowire(service: 'sylius_abstraction.state_machine')]
        private readonly StateMachineInterface $stateMachine,
    ) {
    }

    public function getName(): string
    {
        return 'qa_scenarios';
    }

    public function load(array $options): void
    {
        $this->trackStock();
        $this->dropShortDescriptions();
        $this->keepOnlyEnUsTranslations();
        $this->emptyStock();
        $this->entityManager->flush();

        $this->placeOrders();
        $this->entityManager->flush();
    }

    private function trackStock(): void
    {
        $womensTShirts = $this->taxonRepository->findOneBy(['code' => 'womens_t_shirts']);
        Assert::isInstanceOf($womensTShirts, TaxonInterface::class);
        foreach ($this->productRepository->findBy(['mainTaxon' => $womensTShirts]) as $product) {
            foreach ($product->getVariants() as $variant) {
                Assert::isInstanceOf($variant, ProductVariantInterface::class);
                $variant->setTracked(true);
            }
        }

        foreach ($this->product(self::STELLAR_DRIFT)->getVariants() as $variant) {
            Assert::isInstanceOf($variant, ProductVariantInterface::class);
            $variant->setTracked(true);
            $variant->setOnHand(self::STELLAR_DRIFT_STOCK[$this->sizeOf($variant)] ?? 0);
        }
    }

    private function dropShortDescriptions(): void
    {
        foreach (self::WITHOUT_SHORT_DESCRIPTION as $code) {
            foreach ($this->product($code)->getTranslations() as $translation) {
                Assert::isInstanceOf($translation, ProductTranslationInterface::class);
                $translation->setShortDescription(null);
            }
        }
    }

    private function keepOnlyEnUsTranslations(): void
    {
        foreach (self::EN_US_ONLY as $code) {
            $product = $this->product($code);
            foreach ($product->getTranslations() as $translation) {
                Assert::isInstanceOf($translation, ProductTranslationInterface::class);
                if ('en_US' !== $translation->getLocale()) {
                    $product->removeTranslation($translation);
                }
            }
        }
    }

    private function emptyStock(): void
    {
        foreach (self::OUT_OF_STOCK as $code) {
            foreach ($this->product($code)->getVariants() as $variant) {
                Assert::isInstanceOf($variant, ProductVariantInterface::class);
                $variant->setTracked(true);
                $variant->setOnHand(0);
            }
        }
    }

    /**
     * Each order goes through the checkout as OrderExampleFactory drives it,
     * keeping the shipping method the shop picks for the address, then its
     * payment is completed; its shipment stays ready (not shipped).
     */
    private function placeOrders(): void
    {
        $channel = $this->channelRepository->findOneBy(['code' => self::CHANNEL]);
        Assert::isInstanceOf($channel, ChannelInterface::class);
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => 'bank_transfer']);
        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        $currencyCode = $channel->getBaseCurrency()?->getCode();
        $localeCode = $channel->getDefaultLocale()?->getCode();
        Assert::notNull($currencyCode);
        Assert::notNull($localeCode);

        foreach (self::ORDERS as [$email, $daysAgo, $size, $quantity]) {
            $completedAt = (new \DateTime('today'))->modify(sprintf('-%d days', $daysAgo))->setTime(10, 30);
            $customer = $this->customer($email);

            $order = $this->orderFactory->createNew();
            $order->setChannel($channel);
            $order->setCustomer($customer);
            $order->setCurrencyCode($currencyCode);
            $order->setLocaleCode($localeCode);

            $item = $this->orderItemFactory->createNew();
            $item->setVariant($this->variant(self::STELLAR_DRIFT, $size));
            $this->orderItemQuantityModifier->modify($item, $quantity);
            $order->addItem($item);

            $address = $this->address($email);
            $order->setShippingAddress($address);
            $order->setBillingAddress(clone $address);
            $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_ADDRESS);

            if (OrderCheckoutStates::STATE_SHIPPING_SKIPPED !== $order->getCheckoutState()) {
                foreach ($order->getShipments() as $shipment) {
                    $shipment->setCreatedAt($completedAt);
                }
                $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);
            }
            if (OrderCheckoutStates::STATE_PAYMENT_SKIPPED !== $order->getCheckoutState()) {
                foreach ($order->getPayments() as $payment) {
                    $payment->setMethod($paymentMethod);
                    $payment->setCreatedAt($completedAt);
                }
                $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);
            }
            $this->stateMachine->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE);
            $order->setCheckoutCompletedAt($completedAt);

            foreach ($order->getPayments() as $payment) {
                $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE);
            }

            $this->entityManager->persist($order);
            $this->entityManager->flush();
        }
    }

    private function customer(string $email): CustomerInterface
    {
        $customer = $this->customerRepository->findOneBy(['email' => $email]);
        if ($customer instanceof CustomerInterface) {
            return $customer;
        }

        [$firstName, $lastName] = self::CUSTOMERS[$email];
        $customer = $this->customerFactory->createNew();
        $customer->setEmail($email);
        $customer->setFirstName($firstName);
        $customer->setLastName($lastName);
        $this->entityManager->persist($customer);

        return $customer;
    }

    private function address(string $email): AddressInterface
    {
        [$firstName, $lastName, $street, $city, $postcode] = self::CUSTOMERS[$email];
        $address = $this->addressFactory->createNew();
        $address->setFirstName($firstName);
        $address->setLastName($lastName);
        $address->setStreet($street);
        $address->setCity($city);
        $address->setPostcode($postcode);
        $address->setCountryCode('US');

        return $address;
    }

    private function product(string $code): ProductInterface
    {
        $product = $this->productRepository->findOneBy(['code' => $code]);
        Assert::isInstanceOf($product, ProductInterface::class, sprintf('Product "%s" is not in the fixtures.', $code));

        return $product;
    }

    private function variant(string $productCode, string $size): ProductVariantInterface
    {
        foreach ($this->product($productCode)->getVariants() as $variant) {
            Assert::isInstanceOf($variant, ProductVariantInterface::class);
            if ($size === $this->sizeOf($variant)) {
                return $variant;
            }
        }

        throw new \InvalidArgumentException(sprintf('Product "%s" has no variant in size "%s".', $productCode, $size));
    }

    private function sizeOf(ProductVariantInterface $variant): ?string
    {
        foreach ($variant->getOptionValues() as $optionValue) {
            if ('t_shirt_size' === $optionValue->getOptionCode()) {
                return $optionValue->getCode();
            }
        }

        return null;
    }
}
