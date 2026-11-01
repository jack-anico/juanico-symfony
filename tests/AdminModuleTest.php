<?php

namespace App\Tests;

use App\Entity\PlatformSetting;
use App\Entity\PaymentStatusHistory;
use App\Entity\Payment;
use App\Entity\OrderStatusHistory;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AdminModuleTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $admin;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
        $this->em->persist((new PlatformSetting())->setSettingKey('pending_order_escalation_hours')->setSettingValue('24'));
        $this->admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $this->em->flush();
        $this->client->loginUser($this->admin);
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    /** @return iterable<array{string}> */
    public static function adminPages(): iterable
    {
        foreach (['/admin', '/admin/product', '/admin/product/new', '/admin/category', '/admin/category/new', '/admin/brand', '/admin/brand/new',
            '/admin/discount/code', '/admin/discount/code/new', '/admin/order', '/admin/user', '/admin/review', '/admin/inquiry', '/admin/report', '/admin/payment', '/admin/platform/setting', '/admin/audit/log'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('adminPages')]
    public function testAdminPagesRenderForAdmin(string $path): void
    {
        $this->client->request('GET', $path);

        $this->assertResponseIsSuccessful();
    }

    #[DataProvider('adminPages')]
    public function testAdminPagesAreForbiddenForCustomers(string $path): void
    {
        $customer = $this->user('customer@example.test');
        $this->em->flush();
        $this->client->loginUser($customer);

        $this->client->request('GET', $path);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDashboardShowsKpisAndLowStock(): void
    {
        $product = $this->product('Low Stock Saw', stock: 1, threshold: 5);
        $this->order($this->admin, $product, status: Order::STATUS_DELIVERED, total: '1234.50', paymentStatus: Payment::STATUS_PAID);
        $this->em->flush();

        $this->client->request('GET', '/admin?from=not-a-date');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '₱1,234.50');
        $this->assertSelectorTextContains('body', 'Low Stock Saw');
    }

    public function testCreateProductWithImageAndOpeningStock(): void
    {
        $category = $this->category();
        $this->em->flush();

        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $this->client->request('GET', '/admin/product/new');
        $this->client->submitForm('Save', [
            'product[name]' => 'Claw Hammer',
            'product[sku]' => 'HAM-1',
            'product[slug]' => 'claw-hammer',
            'product[description]' => 'A hammer',
            'product[price]' => '350.00',
            'product[costPrice]' => '200.00',
            'product[unit]' => 'pc',
            'product[stock]' => '12',
            'product[lowStockThreshold]' => '3',
            'product[status]' => Product::STATUS_ACTIVE,
            'product[specifications]' => '{"weight": "16oz"}',
            'product[category]' => (string) $category->getId(),
            'product[imageFile]' => new UploadedFile($path, 'photo.php', 'image/png', null, true),
        ]);

        $this->assertResponseRedirects('/admin/product');

        $product = $this->em->getRepository(Product::class)->findOneBy(['sku' => 'HAM-1']);
        $this->assertSame(12, $product->getStock());
        $this->assertNotNull($product->getCreatedAt());
        $this->assertSame(['weight' => '16oz'], $product->getSpecifications());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $product->getImage(), 'Client filename is never trusted');

        $stored = static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/products/'.$product->getImage();
        $this->assertFileExists($stored);
        unlink($stored);
    }

    public function testNonImageUploadIsRejected(): void
    {
        $category = $this->category();
        $this->em->flush();

        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, '<?php echo "x";');

        $this->client->request('GET', '/admin/product/new');
        $this->client->submitForm('Save', [
            'product[name]' => 'Bad',
            'product[sku]' => 'BAD-1',
            'product[slug]' => 'bad',
            'product[description]' => 'Bad',
            'product[price]' => '1.00',
            'product[costPrice]' => '1.00',
            'product[unit]' => 'pc',
            'product[stock]' => '1',
            'product[lowStockThreshold]' => '1',
            'product[status]' => Product::STATUS_ACTIVE,
            'product[category]' => (string) $category->getId(),
            'product[imageFile]' => new UploadedFile($path, 'photo.png', 'image/png', null, true),
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertNull($this->em->getRepository(Product::class)->findOneBy(['sku' => 'BAD-1']));
    }

    public function testInvalidSpecificationsJsonIsRejected(): void
    {
        $product = $this->product('Saw');
        $this->em->flush();

        $this->client->request('GET', '/admin/product/'.$product->getId().'/edit');
        $this->client->submitForm('Update', ['product[specifications]' => '{not json']);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testEditFormCannotChangeStockDirectly(): void
    {
        $product = $this->product('Saw');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/product/'.$product->getId().'/edit');

        $this->assertCount(0, $crawler->filter('[name="product[stock]"]'));
    }

    public function testStockAdjustmentUpdatesStockAndWritesMovement(): void
    {
        $product = $this->product('Saw', stock: 10);
        $this->em->flush();

        $this->client->request('GET', '/admin/product/'.$product->getId());
        $this->client->submitForm('Apply adjustment', [
            'stock_adjustment[quantityChange]' => '-4',
            'stock_adjustment[reason]' => 'damaged',
            'stock_adjustment[note]' => 'Water damage',
        ]);
        $this->assertResponseRedirects();

        $product = $this->reload($product);
        $this->assertSame(6, $product->getStock());

        $movement = $this->em->getRepository(StockMovement::class)->findOneBy(['product' => $product]);
        $this->assertSame(-4, $movement->getQuantityChange());
        $this->assertSame('damaged', $movement->getReason());
        $this->assertSame($this->admin->getId(), $movement->getAdmin()->getId());
    }

    public function testStockAdjustmentCannotGoBelowZero(): void
    {
        $product = $this->product('Saw', stock: 2);
        $this->em->flush();

        $this->client->request('GET', '/admin/product/'.$product->getId());
        $this->client->submitForm('Apply adjustment', [
            'stock_adjustment[quantityChange]' => '-3',
            'stock_adjustment[reason]' => 'correction',
        ]);
        $this->client->followRedirect();

        $this->assertSelectorTextContains('.flash-error', 'below zero');
        $product = $this->reload($product);
        $this->assertSame(2, $product->getStock());
        $this->assertSame(0, $this->em->getRepository(StockMovement::class)->count([]));
    }

    public function testDeletingProductUsedByAnOrderShowsErrorInsteadOfCrashing(): void
    {
        $product = $this->product('Saw');
        $this->order($this->admin, $product);
        $this->em->flush();
        $id = $product->getId();

        $this->client->request('GET', '/admin/product/'.$id);
        $this->client->submitForm('Delete');

        $this->assertResponseRedirects('/admin/product');
        $this->client->getContainer()->get('doctrine')->resetManager();
        $em = static::getContainer()->get('doctrine')->getManager();
        $this->assertNotNull($em->find(Product::class, $id));
    }

    public function testOrderMovesThroughAllowedStatusesAndRecordsDates(): void
    {
        $order = $this->order($this->admin, $this->product('Saw'), status: Order::STATUS_SHIPPED);
        $this->em->flush();

        $this->client->request('GET', '/admin/order/'.$order->getId());
        $this->client->submitForm('Save', [
            'order_status[status]' => Order::STATUS_DELIVERED,
            'order_status[trackingNumber]' => 'TRK-1',
        ]);
        $this->assertResponseRedirects();

        $order = $this->reload($order);
        $this->assertSame(Order::STATUS_DELIVERED, $order->getStatus());
        $this->assertSame('TRK-1', $order->getTrackingNumber());
        $this->assertNotNull($order->getDeliveredAt());
    }

    public function testOrderStatusCannotSkipSteps(): void
    {
        $order = $this->order($this->admin, $this->product('Saw'));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin/order/'.$order->getId());
        $form = $crawler->selectButton('Save')->form();
        $values = $form->getPhpValues();
        $values['order_status']['status'] = Order::STATUS_DELIVERED;
        $this->client->request('POST', $form->getUri(), $values);

        $this->assertResponseStatusCodeSame(422);
        $order = $this->reload($order);
        $this->assertSame(Order::STATUS_PENDING, $order->getStatus());
    }

    public function testCancelRequiresReasonAndAllowedStatus(): void
    {
        $order = $this->order($this->admin, $this->product('Saw'));
        $shipped = $this->order($this->admin, $this->product('Hammer'), status: Order::STATUS_SHIPPED);
        $this->em->flush();

        $this->client->request('GET', '/admin/order/'.$order->getId());
        $this->client->submitForm('Cancel order', ['reason' => '   ']);
        $order = $this->reload($order);
        $this->assertSame(Order::STATUS_PENDING, $order->getStatus());

        $this->client->request('GET', '/admin/order/'.$order->getId());
        $this->client->submitForm('Cancel order', ['reason' => 'Customer changed their mind']);
        $order = $this->reload($order);
        $this->assertSame(Order::STATUS_CANCELLED, $order->getStatus());
        $this->assertSame('Customer changed their mind', $order->getCancelReason());

        $crawler = $this->client->request('GET', '/admin/order/'.$shipped->getId());
        $this->assertCount(0, $crawler->selectButton('Cancel order'));
    }

    public function testInvoiceShowsLinesAndTotal(): void
    {
        $order = $this->order($this->admin, $this->product('Printable Saw'), total: '777.00');
        $this->em->flush();

        $this->client->request('GET', '/admin/order/'.$order->getId().'/invoice');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('tbody', 'Printable Saw');
        $this->assertSelectorTextContains('tfoot', '₱777.00');
    }

    public function testUserPagesHidePasswordAndAdminCanDeactivateOthersOnly(): void
    {
        $customer = $this->user('customer@example.test');
        $this->em->flush();

        $this->client->request('GET', '/admin/user');
        $this->assertStringNotContainsString('secret-hash', $this->client->getResponse()->getContent());

        $this->client->request('GET', '/admin/user/'.$customer->getId());
        $this->assertStringNotContainsString('secret-hash', $this->client->getResponse()->getContent());
        $this->client->submitForm('Deactivate');
        $customer = $this->reload($customer);
        $this->assertFalse($customer->isActive());

        $this->client->request('GET', '/admin/user/'.$this->admin->getId());
        $this->client->submitForm('Deactivate');
        $this->admin = $this->reload($this->admin);
        $this->assertTrue($this->admin->isActive());
    }

    public function testReviewModeration(): void
    {
        $review = (new Review())->setAuthor($this->admin)->setProduct($this->product('Saw'))->setRating(5)
            ->setComment('Great')->setIsApproved(false)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($review);
        $this->em->flush();
        $id = $review->getId();

        $this->client->request('GET', '/admin/review');
        $this->client->submitForm('Approve');
        $review = $this->reload($review);
        $this->assertTrue($review->isApproved());

        $this->client->request('GET', '/admin/review');
        $this->client->submitForm('Hide');
        $review = $this->reload($review);
        $this->assertFalse($review->isApproved());

        $this->client->request('GET', '/admin/review');
        $this->client->submitForm('Delete');
        $this->em->clear();
        $this->assertNull($this->em->find(Review::class, $id));
    }

    public function testInquiryStatusChangesAndFilter(): void
    {
        $inquiry = (new Inquiry())->setName('Ana')->setEmail('ana@example.test')->setSubject('Bulk price')
            ->setMessage('Hello')->setStatus(Inquiry::STATUS_NEW)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($inquiry);
        $this->em->flush();

        $this->client->request('GET', '/admin/inquiry/'.$inquiry->getId());
        $this->client->submitForm('Mark as resolved');
        $inquiry = $this->reload($inquiry);
        $this->assertSame(Inquiry::STATUS_RESOLVED, $inquiry->getStatus());

        $this->client->request('GET', '/admin/inquiry?status=new');
        $this->assertSelectorTextNotContains('tbody', 'Bulk price');
        $this->client->request('GET', '/admin/inquiry?status=resolved');
        $this->assertSelectorTextContains('tbody', 'Bulk price');
    }

    public function testActionsRejectMissingCsrfToken(): void
    {
        $customer = $this->user('customer@example.test');
        $this->em->flush();

        $this->client->request('POST', '/admin/user/'.$customer->getId().'/toggle-active');

        $customer = $this->reload($customer);
        $this->assertTrue($customer->isActive());
    }

    public function testNewDiscountCodeStartsWithZeroUses(): void
    {
        $this->client->request('GET', '/admin/discount/code/new');
        $this->client->submitForm('Save', [
            'discount_code[code]' => 'SAVE10',
            'discount_code[type]' => 'percent',
            'discount_code[value]' => '10.00',
        ]);

        $this->assertResponseRedirects('/admin/discount/code');
        $this->assertSame(0, $this->em->getRepository(DiscountCode::class)->findOneBy(['code' => 'SAVE10'])->getTimesUsed());
    }

    public function testCsvExportNeutralizesFormulas(): void
    {
        $product = $this->product('=HYPERLINK("http://evil")');
        $this->order($this->admin, $product, status: Order::STATUS_DELIVERED, paymentStatus: Payment::STATUS_PAID);
        $this->em->flush();

        ob_start();
        $this->client->request('GET', '/admin/report/export.csv');
        $csv = ob_get_clean();
        $csv = $csv ?: $this->client->getInternalResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('SKU,Product,Units,Revenue', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    /** Reads the saved row again, because the entity manager is cleared between requests. */
    private function reload(object $entity): object
    {
        $this->em->clear();

        return $this->em->find($entity::class, $entity->getId());
    }

    private function purge(): void
    {
        $this->em->clear();
        foreach ([
            AuditLog::class, PaymentStatusHistory::class, Payment::class, OrderStatusHistory::class,
            OrderItem::class, Order::class, CartItem::class, WishlistItem::class, Review::class,
            StockMovement::class, Address::class, DiscountCode::class, Product::class, Brand::class,
            Inquiry::class, PlatformSetting::class, User::class,
        ] as $class) {
            $this->em->createQuery('DELETE FROM '.$class)->execute();
        }
        $this->em->getConnection()->executeStatement('UPDATE category SET parent_id = NULL');
        $this->em->createQuery('DELETE FROM '.Category::class)->execute();
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setFullName($email)->setRoles($roles)->setPassword('secret-hash')
            ->setIsActive(true)->setIsVerified(true)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);

        return $user;
    }

    private function category(): Category
    {
        $id = ++$this->sequence;
        $category = (new Category())->setName('Category '.$id)->setSlug('category-'.$id)->setDisplayOrder(0)->setIsActive(true);
        $this->em->persist($category);

        return $category;
    }

    private function product(string $name, int $stock = 10, int $threshold = 2): Product
    {
        $id = ++$this->sequence;
        $product = (new Product())->setName($name)->setSku('SKU-'.$id)->setSlug('product-'.$id)->setDescription('Description')
            ->setPrice('100.00')->setCostPrice('50.00')->setUnit('pc')->setStock($stock)->setLowStockThreshold($threshold)
            ->setStatus(Product::STATUS_ACTIVE)->setIsFeatured(false)->setCreatedAt(new \DateTimeImmutable())
            ->setCategory($this->category());
        $this->em->persist($product);

        return $product;
    }

    private function order(User $customer, Product $product, string $status = Order::STATUS_PENDING, string $total = '100.00', string $paymentStatus = Payment::STATUS_PENDING): Order
    {
        $id = ++$this->sequence;
        $address = (new Address())->setLabel('Home')->setRecipient('Recipient')->setPhone('1')->setLine1('Line')->setCity('City')
            ->setProvince('Province')->setPostalCode('1000')->setIsDefault(false)->setOwner($customer);
        $this->em->persist($address);

        $order = (new Order())->setOrderNumber('ORD-'.$id)->setIdempotencyKey('key-'.$id)->setStatus($status)
            ->setShippingMethod('standard')->setSubtotal($total)->setShippingFee('0.00')
            ->setDiscount('0.00')->setTotal($total)->setCreatedAt(new \DateTimeImmutable())
            ->setCustomer($customer)->setShippingAddress($address);
        $line = (new OrderItem())->setProduct($product)->setQuantity(1)->setUnitPrice($total);
        $order->addItem($line);
        $this->em->persist($order);
        $this->em->persist($line);
        $payment = (new Payment())->setOrder($order)->setMethod(Payment::METHOD_COD)->setStatus($paymentStatus)
            ->setAmount($total)->setCreatedAt(new \DateTimeImmutable());
        $order->setPayment($payment);
        $this->em->persist($payment);

        return $order;
    }
}
