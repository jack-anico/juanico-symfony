<?php

namespace App\DataFixtures;

use App\Entity\Address;
use App\Entity\CartItem;
use App\Entity\Inquiry;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Service\OrderTransitionService;
use App\Service\PaymentTransitionService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo customers and orders so the KPIs have data. All statuses go through the transition services.
 * Passwords are local development credentials only; never load fixtures in production.
 */
class DemoOrderFixtures extends Fixture implements DependentFixtureInterface
{
    private const SHIPPING_FEE = '150.00';

    /** customer, days ago, final status, payment method, whether paid, [product index => quantity] */
    private const ORDERS = [
        [0, 28, Order::STATUS_DELIVERED, Payment::METHOD_COD, true, [0 => 2, 12 => 5]],
        [0, 25, Order::STATUS_DELIVERED, Payment::METHOD_GCASH, true, [7 => 1]],
        [1, 22, Order::STATUS_DELIVERED, Payment::METHOD_CARD, true, [20 => 10, 18 => 4]],
        [1, 19, Order::STATUS_DELIVERED, Payment::METHOD_BANK, true, [22 => 3, 15 => 2]],
        [2, 16, Order::STATUS_DELIVERED, Payment::METHOD_COD, true, [24 => 2, 27 => 1]],
        [0, 14, Order::STATUS_DELIVERED, Payment::METHOD_COD, false, [3 => 1, 13 => 3]],
        [2, 12, Order::STATUS_CANCELLED, Payment::METHOD_GCASH, true, [5 => 1]],
        [1, 10, Order::STATUS_CANCELLED, Payment::METHOD_COD, false, [8 => 1]],
        [0, 8, Order::STATUS_SHIPPED, Payment::METHOD_GCASH, true, [9 => 2]],
        [2, 6, Order::STATUS_PACKED, Payment::METHOD_BANK, true, [21 => 4]],
        [1, 4, Order::STATUS_CONFIRMED, Payment::METHOD_BANK, false, [23 => 5]],
        [0, 3, Order::STATUS_CONFIRMED, Payment::METHOD_COD, false, [19 => 2]],
        [2, 2, Order::STATUS_PENDING, Payment::METHOD_COD, false, [10 => 1]],
        [1, 1, Order::STATUS_PENDING, Payment::METHOD_BANK, false, [26 => 1]],
    ];

    /** The steps from pending to each final status. */
    private const PATHS = [
        Order::STATUS_PENDING => [],
        Order::STATUS_CONFIRMED => [Order::STATUS_CONFIRMED],
        Order::STATUS_PACKED => [Order::STATUS_CONFIRMED, Order::STATUS_PACKED],
        Order::STATUS_SHIPPED => [Order::STATUS_CONFIRMED, Order::STATUS_PACKED, Order::STATUS_SHIPPED],
        Order::STATUS_DELIVERED => [Order::STATUS_CONFIRMED, Order::STATUS_PACKED, Order::STATUS_SHIPPED, Order::STATUS_DELIVERED],
    ];

    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private OrderTransitionService $orderTransitions,
        private PaymentTransitionService $paymentTransitions,
    ) {
    }

    public function getDependencies(): array
    {
        return [AdminUserFixtures::class, CatalogFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $admin = $this->getReference('admin-user', User::class);
        $customers = [];
        $addresses = [];
        foreach (['Maria Santos', 'Jose Reyes', 'Ana Cruz'] as $index => $name) {
            $email = 0 === $index ? 'customer@juanico.test' : sprintf('customer%d@juanico.test', $index + 1);
            $customer = (new User())->setEmail($email)->setFullName($name)->setPhone('0917000000'.$index)
                ->setIsActive(true)->setIsVerified(true)->setCreatedAt(new \DateTimeImmutable(sprintf('-%d days', 40 - $index * 5)));
            $customer->setPassword($this->passwordHasher->hashPassword($customer, 'Customer123!'));
            $manager->persist($customer);
            $customers[] = $customer;

            $address = (new Address())->setLabel('Home')->setRecipient($name)->setPhone('0917000000'.$index)
                ->setLine1(sprintf('%d Rizal Street', $index + 10))->setCity('Quezon City')->setProvince('Metro Manila')
                ->setPostalCode('1100')->setIsDefault(true)->setOwner($customer);
            $manager->persist($address);
            $addresses[] = $address;
        }
        $manager->flush();

        foreach (self::ORDERS as $index => [$customerIndex, $daysAgo, $status, $method, $paid, $lines]) {
            $this->createOrder($index, $customers[$customerIndex], $addresses[$customerIndex], $admin, $daysAgo, $status, $method, $paid, $lines, $manager);
        }

        $this->createExtras($customers, $manager);
        $manager->flush();
    }

    /** @param array<int, int> $lines */
    private function createOrder(int $index, User $customer, Address $address, User $admin, int $daysAgo, string $status, string $method, bool $paid, array $lines, ObjectManager $manager): void
    {
        $placedAt = new \DateTimeImmutable(sprintf('-%d days', $daysAgo));
        $subtotal = '0.00';

        $order = (new Order())
            ->setOrderNumber(sprintf('JNC-DEMO-%04d', $index + 1))
            ->setIdempotencyKey(sprintf('00000000-0000-4000-8000-%012d', $index + 1))
            ->setShippingMethod(Order::SHIPPING_STANDARD)
            ->setCreatedAt($placedAt)
            ->setCustomer($customer)
            ->setShippingAddress($address);

        foreach ($lines as $productIndex => $quantity) {
            $product = $this->getReference('product-'.$productIndex, Product::class);
            $price = (string) $product->getEffectivePrice();
            $line = (new OrderItem())->setProduct($product)->setQuantity($quantity)->setUnitPrice($price);
            $order->addItem($line);
            $manager->persist($line);
            $product->setStock($product->getStock() - $quantity);
            $subtotal = bcadd($subtotal, bcmul($price, (string) $quantity, 2), 2);
        }

        $total = bcadd($subtotal, self::SHIPPING_FEE, 2);
        $order->setSubtotal($subtotal)->setShippingFee(self::SHIPPING_FEE)->setDiscount('0.00')->setTotal($total);
        $this->orderTransitions->start($order, $customer);
        $manager->persist($order);

        $payment = (new Payment())->setMethod($method)->setAmount($total)->setCreatedAt($placedAt)->setOrder($order);
        if (in_array($method, [Payment::METHOD_GCASH, Payment::METHOD_CARD], true)) {
            $payment->setReference(sprintf('SIM-DEMO%04d', $index + 1));
        }
        if (Payment::METHOD_BANK === $method) {
            $payment->setProofFile('demo-proof.png');
        }
        $order->setPayment($payment);
        $this->paymentTransitions->start($payment, $customer);
        $manager->persist($payment);
        $manager->flush();

        // Bank orders need payment before packing; cash is collected only after delivery.
        if ($paid && Payment::METHOD_COD !== $method) {
            $this->paymentTransitions->transition($payment, Payment::STATUS_PAID, $admin);
        }

        if (Order::STATUS_CANCELLED === $status) {
            $this->orderTransitions->transition($order, Order::STATUS_CANCELLED, $admin, 'Demo cancellation');
        } else {
            foreach (self::PATHS[$status] as $next) {
                $this->orderTransitions->transition($order, $next, $admin);
            }
        }

        if ($paid && Payment::METHOD_COD === $method) {
            $this->paymentTransitions->transition($payment, Payment::STATUS_PAID, $admin);
        }

        // Spread the dates across past days so the sales charts and fulfillment KPI have something to show.
        if (null !== $payment->getPaidAt()) {
            $payment->setPaidAt($placedAt->modify('+2 days'));
        }
        if (null !== $order->getDeliveredAt()) {
            $order->setDeliveredAt($placedAt->modify('+3 days'));
        }
        $manager->flush();
    }

    /** @param list<User> $customers */
    private function createExtras(array $customers, ObjectManager $manager): void
    {
        $now = new \DateTimeImmutable();

        $manager->persist((new Review())->setAuthor($customers[0])->setProduct($this->getReference('product-0', Product::class))
            ->setRating(5)->setComment('Solid hammer with a good grip.')->setIsApproved(true)->setCreatedAt($now->modify('-20 days')));
        $manager->persist((new Review())->setAuthor($customers[1])->setProduct($this->getReference('product-20', Product::class))
            ->setRating(4)->setComment('Good cement, delivered on time.')->setIsApproved(true)->setCreatedAt($now->modify('-15 days')));
        $manager->persist((new Review())->setAuthor($customers[2])->setProduct($this->getReference('product-24', Product::class))
            ->setRating(3)->setComment('Waiting for moderation.')->setIsApproved(false)->setCreatedAt($now->modify('-5 days')));

        // An abandoned cart for the KPI, and a fresh one.
        $manager->persist((new CartItem())->setOwner($customers[1])->setProduct($this->getReference('product-1', Product::class))
            ->setQuantity(2)->setCreatedAt($now->modify('-3 days'))->setUpdatedAt($now->modify('-3 days')));
        $manager->persist((new CartItem())->setOwner($customers[0])->setProduct($this->getReference('product-3', Product::class))
            ->setQuantity(1)->setCreatedAt($now)->setUpdatedAt($now));

        $manager->persist((new WishlistItem())->setOwner($customers[0])->setProduct($this->getReference('product-7', Product::class))->setCreatedAt($now));

        $manager->persist((new Inquiry())->setName('Pedro Lim')->setEmail('pedro@example.test')->setSubject('Bulk cement price')
            ->setMessage('Do you offer discounts on 100 bags?')->setStatus(Inquiry::STATUS_NEW)->setCreatedAt($now->modify('-1 day')));
        $manager->persist((new Inquiry())->setName('Liza Tan')->setEmail('liza@example.test')->setSubject('Delivery to Cavite')
            ->setMessage('How long does delivery take?')->setStatus(Inquiry::STATUS_RESOLVED)->setCreatedAt($now->modify('-9 days')));
    }
}
