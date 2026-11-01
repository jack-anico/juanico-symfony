<?php

namespace App\Tests;

use App\Entity\PlatformSetting;
use App\Entity\PaymentStatusHistory;
use App\Entity\Payment;
use App\Entity\OrderStatusHistory;
use App\Entity\DeliveryZone;
use App\Entity\AuditLog;
use App\Entity\Address;
use App\Entity\Brand;
use App\Entity\CartItem;
use App\Entity\Category;
use App\Entity\DiscountCode;
use App\Entity\Inquiry;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\Review;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Entity\WishlistItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RepositoryQueriesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private int $sequence = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testSearchFiltersAndSorts(): void
    {
        $parent = $this->category('Tools');
        $child = $this->category('Saws', $parent);
        $other = $this->category('Paint');
        $brand = $this->brand();

        $saw = $this->product('Hand Saw', $child, price: '500.00', salePrice: '300.00', brand: $brand);
        $hammer = $this->product('Hammer', $parent, price: '400.00');
        $this->product('Red Paint', $other, price: '900.00');
        $this->product('Draft Saw', $child, status: Product::STATUS_DRAFT);
        $this->em->flush();

        $repo = $this->em->getRepository(Product::class);

        $this->assertSame([$saw], $repo->search(q: 'hand')->getResult());
        $this->assertSame([], $repo->search(q: '%')->getResult(), 'LIKE wildcards are escaped');
        $this->assertEqualsCanonicalizing([$saw, $hammer], $repo->search(categoryId: $parent->getId())->getResult());
        $this->assertSame([$saw], $repo->search(brandId: $brand->getId())->getResult());
        $this->assertSame([$saw], $repo->search(maxPrice: '350.00')->getResult(), 'Filters on the sale price');
        $this->assertSame([$hammer], $repo->search(minPrice: '350.00', maxPrice: '500.00')->getResult());

        $byPrice = $repo->search(sort: 'price_asc')->getResult();
        $this->assertSame(['Hand Saw', 'Hammer', 'Red Paint'], array_map(fn (Product $p) => $p->getName(), $byPrice));
        $this->assertSame('Red Paint', $repo->search(sort: 'price_desc')->getResult()[0]->getName());
        $this->assertSame('Hammer', $repo->search(sort: 'name')->getResult()[0]->getName());
    }

    public function testProductListings(): void
    {
        $category = $this->category('Tools');
        $featured = $this->product('Featured', $category, featured: true, stock: 50);
        $onSale = $this->product('On Sale', $category, price: '100.00', salePrice: '80.00', stock: 50);
        $low = $this->product('Low', $category, stock: 3, threshold: 5);
        $out = $this->product('Out', $category, stock: 0);
        $this->product('Archived Low', $category, stock: 1, threshold: 5, status: Product::STATUS_ARCHIVED);
        $this->em->flush();

        $repo = $this->em->getRepository(Product::class);

        $this->assertSame([$featured], $repo->findFeatured());
        $this->assertSame([$onSale], $repo->findOnSale());
        $this->assertSame([$low], $repo->findLowStock());
        $this->assertSame([$out], $repo->findOutOfStock());
        $this->assertCount(3, $repo->findRelated($featured));
        $this->assertNotContains($featured, $repo->findRelated($featured));
    }

    public function testInventoryValueUsesCostPrice(): void
    {
        $category = $this->category('Tools');
        $this->product('A', $category, cost: '10.50', stock: 2);
        $this->product('B', $category, cost: '5.00', stock: 3);
        $this->product('None', $category, cost: '99.00', stock: 0);
        $this->em->flush();

        $this->assertSame('36.00', $this->em->getRepository(Product::class)->inventoryValue());
    }

    public function testBestSellersAndSlowMovingIgnoreCancelledOrders(): void
    {
        $customer = $this->user('buyer@example.test');
        $category = $this->category('Tools');
        $old = new \DateTimeImmutable('-60 days');
        $popular = $this->product('Popular', $category, createdAt: $old);
        $modest = $this->product('Modest', $category, createdAt: $old);
        $cancelled = $this->product('Only Cancelled', $category, createdAt: $old);
        $brandNew = $this->product('Brand New', $category);

        $this->order($customer, '100.00', items: [[$popular, 5], [$modest, 1]]);
        $this->order($customer, '100.00', status: Order::STATUS_CANCELLED, items: [[$cancelled, 50]]);
        $this->em->flush();

        $repo = $this->em->getRepository(Product::class);

        $sellers = $repo->findBestSellers(5);
        $this->assertCount(2, $sellers);
        $this->assertSame($popular, $sellers[0]['product']);
        $this->assertSame(5, $sellers[0]['sold']);

        $slow = $repo->findSlowMoving(30);
        $this->assertSame([$cancelled], $slow);
        $this->assertNotContains($brandNew, $slow);
    }

    public function testCategoryQueries(): void
    {
        $root = $this->category('Tools');
        $inactive = $this->category('Hidden', active: false);
        $child = $this->category('Saws', $root);
        $this->category('Hidden Child', $root, active: false);
        $saw = $this->product('Saw', $child);
        $customer = $this->user('buyer@example.test');

        $this->order($customer, '250.00', status: Order::STATUS_DELIVERED, payment: Payment::STATUS_PAID, items: [[$saw, 2, '100.00']]);
        $this->order($customer, '999.00', status: Order::STATUS_CANCELLED, items: [[$saw, 9, '111.00']]);
        $this->order($customer, '999.00', status: Order::STATUS_DELIVERED, payment: Payment::STATUS_REFUNDED, items: [[$saw, 9, '111.00']]);
        $this->order($customer, '999.00', status: Order::STATUS_SHIPPED, payment: Payment::STATUS_PAID, items: [[$saw, 9, '111.00']]);
        $this->em->flush();

        $repo = $this->em->getRepository(Category::class);

        $this->assertSame([$root], $repo->findActiveRoots());
        $this->assertNotContains($inactive, $repo->findActiveRoots());
        $this->assertSame([$child], $repo->findChildren($root));

        $revenue = $repo->revenueByCategory(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day'));
        $this->assertCount(1, $revenue);
        $this->assertSame('Saws', $revenue[0]['name']);
        $this->assertEquals(200, $revenue[0]['revenue']);
    }

    public function testOrderKpis(): void
    {
        $customer = $this->user('buyer@example.test');
        $now = new \DateTimeImmutable();

        $this->order($customer, '100.00', status: Order::STATUS_DELIVERED, payment: Payment::STATUS_PAID);
        $this->order($customer, '300.00', status: Order::STATUS_DELIVERED, payment: Payment::STATUS_PAID, createdAt: $now->modify('-10 hours'), deliveredAt: $now);
        $this->order($customer, '500.00', status: Order::STATUS_CANCELLED, payment: Payment::STATUS_PAID);
        $this->order($customer, '700.00');
        $this->order($customer, '900.00', createdAt: $now->modify('-40 days'));
        $this->em->flush();

        $repo = $this->em->getRepository(Order::class);
        $from = $now->modify('-1 day');
        $to = $now->modify('+1 day');

        $this->assertSame('400.00', $repo->totalRevenueBetween($from, $to));
        $this->assertSame(4, $repo->countBetween($from, $to));
        $this->assertSame('200.00', $repo->averageOrderValue($from, $to));
        $this->assertSame('0.00', $repo->totalRevenueBetween($now->modify('-9 days'), $now->modify('-8 days')));

        $counts = $repo->countByStatus();
        $this->assertSame(2, $counts[Order::STATUS_PENDING]);
        $this->assertSame(2, $counts[Order::STATUS_DELIVERED]);
        $this->assertSame(1, $counts[Order::STATUS_CANCELLED]);
        $this->assertEqualsWithDelta(0.2, $repo->cancellationRate(), 0.0001);
        $this->assertEqualsWithDelta(10.0, $repo->averageFulfillmentHours(), 0.05);

        $this->assertCount(1, $repo->findPendingOlderThan(24));

        $days = $repo->salesByDay($now->modify('-2 days'), $to);
        $this->assertEquals(400, array_sum(array_column($days, 'revenue')));
        $this->assertSame(2, array_sum(array_column($days, 'orders')));
    }

    public function testOrderKpisWithNoOrders(): void
    {
        $repo = $this->em->getRepository(Order::class);
        $now = new \DateTimeImmutable();

        $this->assertSame('0.00', $repo->totalRevenueBetween($now->modify('-1 day'), $now));
        $this->assertSame('0.00', $repo->averageOrderValue($now->modify('-1 day'), $now));
        $this->assertSame(0.0, $repo->cancellationRate());
        $this->assertNull($repo->averageFulfillmentHours());
        $this->assertSame([], $repo->salesByDay($now->modify('-1 day'), $now));
    }

    public function testFindForCustomerIsScopedToThatCustomer(): void
    {
        $mine = $this->user('mine@example.test');
        $other = $this->user('other@example.test');
        $pending = $this->order($mine, '10.00');
        $cancelled = $this->order($mine, '10.00', status: Order::STATUS_CANCELLED);
        $this->order($other, '10.00');
        $this->em->flush();

        $repo = $this->em->getRepository(Order::class);

        $this->assertEqualsCanonicalizing([$pending, $cancelled], $repo->findForCustomer($mine)->getResult());
        $this->assertSame([$cancelled], $repo->findForCustomer($mine, Order::STATUS_CANCELLED)->getResult());
    }

    public function testUserQueries(): void
    {
        $repeat = $this->user('repeat@example.test');
        $once = $this->user('once@example.test');
        $idle = $this->user('idle@example.test');
        $this->user('admin@example.test', ['ROLE_ADMIN']);

        $this->order($repeat, '100.00', status: Order::STATUS_DELIVERED, payment: Payment::STATUS_PAID);
        $this->order($repeat, '200.00', status: Order::STATUS_DELIVERED, payment: Payment::STATUS_PAID);
        $this->order($once, '50.00', createdAt: new \DateTimeImmutable('-90 days'));
        $this->em->flush();

        $repo = $this->em->getRepository(User::class);

        $this->assertSame(3, $repo->countNewBetween(new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day')));
        $this->assertSame([$repeat], $repo->findActiveCustomers(30));
        $this->assertNotContains($idle, $repo->findActiveCustomers(365));

        $top = $repo->topBuyers(5);
        $this->assertSame($repeat, $top[0]['user']);
        $this->assertSame(2, $top[0]['orderCount']);
        $this->assertEquals(300, $top[0]['totalSpent']);

        $this->assertSame(0.5, $repo->repeatCustomerRate());
    }

    public function testCartAndWishlistAreScopedToOwner(): void
    {
        $mine = $this->user('mine@example.test');
        $other = $this->user('other@example.test');
        $category = $this->category('Tools');
        $product = $this->product('Saw', $category);
        $unused = $this->product('Hammer', $category);

        $cart = (new CartItem())->setOwner($mine)->setProduct($product)->setQuantity(2)
            ->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $wish = (new WishlistItem())->setOwner($mine)->setProduct($product)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($cart);
        $this->em->persist($wish);
        $this->em->flush();

        $carts = $this->em->getRepository(CartItem::class);
        $this->assertSame([$cart], $carts->findByOwner($mine));
        $this->assertSame([], $carts->findByOwner($other));
        $this->assertSame($cart, $carts->findOneByOwnerAndProduct($mine, $product));
        $this->assertNull($carts->findOneByOwnerAndProduct($mine, $unused));

        $wishes = $this->em->getRepository(WishlistItem::class);
        $this->assertSame([$wish], $wishes->findByOwner($mine));
        $this->assertTrue($wishes->existsFor($mine, $product));
        $this->assertFalse($wishes->existsFor($other, $product));
    }

    public function testReviewQueries(): void
    {
        $author = $this->user('author@example.test');
        $product = $this->product('Saw', $this->category('Tools'));

        $five = $this->review($author, $product, 5, true);
        $this->review($author, $product, 2, true);
        $pending = $this->review($author, $product, 1, false);
        $this->em->flush();

        $repo = $this->em->getRepository(Review::class);

        $this->assertCount(2, $repo->findApprovedForProduct($product));
        $this->assertContains($five, $repo->findApprovedForProduct($product));
        $this->assertSame([$pending], $repo->findPending());
        $this->assertSame(3.5, $repo->averageRating($product));

        $unrated = $this->product('Unrated', $product->getCategory());
        $this->em->flush();
        $this->assertNull($repo->averageRating($unrated));
    }

    public function testInquiryQueries(): void
    {
        $new = $this->inquiry(Inquiry::STATUS_NEW);
        $this->inquiry(Inquiry::STATUS_READ);
        $this->inquiry(Inquiry::STATUS_RESOLVED);
        $this->em->flush();

        $repo = $this->em->getRepository(Inquiry::class);

        $this->assertSame(2, $repo->countUnresolved());
        $this->assertSame([$new], $repo->findByStatus(Inquiry::STATUS_NEW));
    }

    public function testDiscountCodeValidity(): void
    {
        $this->discount('GOOD', minSpend: '500.00');
        $this->discount('OFF', active: false);
        $this->discount('EXPIRED', expiresAt: new \DateTimeImmutable('-1 day'));
        $this->discount('USEDUP', usageLimit: 2, timesUsed: 2);
        $this->discount('FUTURE', expiresAt: new \DateTimeImmutable('+1 day'), usageLimit: 2, timesUsed: 1);
        $this->em->flush();

        $repo = $this->em->getRepository(DiscountCode::class);

        $this->assertNotNull($repo->findValidByCode('GOOD', '500.00'));
        $this->assertNull($repo->findValidByCode('GOOD', '499.99'));
        $this->assertNull($repo->findValidByCode('OFF', '1000.00'));
        $this->assertNull($repo->findValidByCode('EXPIRED', '1000.00'));
        $this->assertNull($repo->findValidByCode('USEDUP', '1000.00'));
        $this->assertNotNull($repo->findValidByCode('FUTURE', '1.00'));
        $this->assertNull($repo->findValidByCode('MISSING', '1000.00'));
    }

    private function purge(): void
    {
        foreach ([
            AuditLog::class, PaymentStatusHistory::class, Payment::class, OrderStatusHistory::class,
            OrderItem::class, Order::class, CartItem::class, WishlistItem::class, Review::class,
            StockMovement::class, Address::class, DiscountCode::class, Product::class, Brand::class,
            Inquiry::class, DeliveryZone::class, PlatformSetting::class, User::class,
        ] as $class) {
            $this->em->createQuery('DELETE FROM '.$class)->execute();
        }
        $this->em->getConnection()->executeStatement('UPDATE category SET parent_id = NULL');
        $this->em->createQuery('DELETE FROM '.Category::class)->execute();
        $this->em->clear();
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setFullName($email)->setRoles($roles)->setPassword('x')
            ->setIsActive(true)->setIsVerified(true)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);

        return $user;
    }

    private function category(string $name, ?Category $parent = null, bool $active = true): Category
    {
        $category = (new Category())->setName($name)->setSlug(strtolower($name).'-'.++$this->sequence)
            ->setDisplayOrder(0)->setIsActive($active)->setParent($parent);
        $this->em->persist($category);

        return $category;
    }

    private function brand(): Brand
    {
        $brand = (new Brand())->setName('Brand')->setIsActive(true);
        $this->em->persist($brand);

        return $brand;
    }

    private function product(
        string $name,
        Category $category,
        string $price = '100.00',
        ?string $salePrice = null,
        string $cost = '50.00',
        int $stock = 10,
        int $threshold = 2,
        string $status = Product::STATUS_ACTIVE,
        bool $featured = false,
        ?Brand $brand = null,
        ?\DateTimeImmutable $createdAt = null,
    ): Product {
        $id = ++$this->sequence;
        $product = (new Product())->setName($name)->setSku('SKU-'.$id)->setSlug('product-'.$id)->setDescription($name)
            ->setPrice($price)->setCostPrice($cost)->setSalePrice($salePrice)->setUnit('pc')->setStock($stock)
            ->setLowStockThreshold($threshold)->setStatus($status)->setIsFeatured($featured)
            ->setCreatedAt($createdAt ?? new \DateTimeImmutable())->setCategory($category)->setBrand($brand);
        $this->em->persist($product);

        return $product;
    }

    /** @param list<array{0: Product, 1: int, 2?: string}> $items */
    private function order(
        User $customer,
        string $total,
        string $status = Order::STATUS_PENDING,
        string $payment = Payment::STATUS_PENDING,
        ?\DateTimeImmutable $createdAt = null,
        ?\DateTimeImmutable $deliveredAt = null,
        array $items = [],
    ): Order {
        $id = ++$this->sequence;
        $address = (new Address())->setLabel('Home')->setRecipient('R')->setPhone('1')->setLine1('L')->setCity('C')
            ->setProvince('P')->setPostalCode('1')->setIsDefault(false)->setOwner($customer);
        $this->em->persist($address);

        $order = (new Order())->setOrderNumber('ORD-'.$id)->setIdempotencyKey('key-'.$id)->setStatus($status)
            ->setShippingMethod('standard')->setSubtotal($total)->setShippingFee('0.00')
            ->setDiscount('0.00')->setTotal($total)->setCreatedAt($createdAt ?? new \DateTimeImmutable())
            ->setDeliveredAt($deliveredAt)->setCustomer($customer)->setShippingAddress($address);
        $this->em->persist($order);
        $this->em->persist((new Payment())->setOrder($order)->setMethod(Payment::METHOD_COD)->setStatus($payment)
            ->setAmount($total)->setCreatedAt(new \DateTimeImmutable()));

        foreach ($items as $item) {
            $line = (new OrderItem())->setProduct($item[0])->setQuantity($item[1])->setUnitPrice($item[2] ?? '10.00');
            $order->addItem($line);
            $this->em->persist($line);
        }

        return $order;
    }

    private function review(User $author, Product $product, int $rating, bool $approved): Review
    {
        $review = (new Review())->setAuthor($author)->setProduct($product)->setRating($rating)->setComment('Comment')
            ->setIsApproved($approved)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($review);

        return $review;
    }

    private function inquiry(string $status): Inquiry
    {
        $inquiry = (new Inquiry())->setName('N')->setEmail('n@example.test')->setSubject('S')->setMessage('M')
            ->setStatus($status)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($inquiry);

        return $inquiry;
    }

    private function discount(
        string $code,
        ?string $minSpend = null,
        bool $active = true,
        ?\DateTimeImmutable $expiresAt = null,
        ?int $usageLimit = null,
        int $timesUsed = 0,
    ): DiscountCode {
        $discount = (new DiscountCode())->setCode($code)->setType('percent')->setValue('10.00')->setMinSpend($minSpend)
            ->setIsActive($active)->setExpiresAt($expiresAt)->setUsageLimit($usageLimit)->setTimesUsed($timesUsed);
        $this->em->persist($discount);

        return $discount;
    }
}
