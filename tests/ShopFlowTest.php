<?php

namespace App\Tests;

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
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ShopFlowTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $customer;
    private Address $address;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->purge();
        $this->customer = $this->user('customer@example.test');
        $this->address = $this->address($this->customer);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testPublicPagesWorkWithoutLogin(): void
    {
        $product = $this->product('Hand Saw');
        $this->em->flush();

        foreach (['/', '/catalog', '/catalog?q=saw&sort=price_asc&minPrice=1', '/product/'.$product->getSlug(), '/contact', '/tools/material-calculator'] as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseIsSuccessful($path);
        }
        $this->assertSelectorTextContains('body', 'Material Calculator');
    }

    public function testCatalogFiltersAndHidesInactiveProducts(): void
    {
        $this->product('Hand Saw');
        $this->product('Claw Hammer');
        $this->product('Secret Draft', status: Product::STATUS_DRAFT);
        $this->em->flush();

        $this->client->request('GET', '/catalog?q=saw');
        $body = $this->client->getResponse()->getContent();

        $this->assertStringContainsString('Hand Saw', $body);
        $this->assertStringNotContainsString('Claw Hammer', $body);
        $this->assertStringNotContainsString('Secret Draft', $body);
    }

    public function testDraftProductPageIsNotFound(): void
    {
        $product = $this->product('Secret Draft', status: Product::STATUS_DRAFT);
        $this->em->flush();

        $this->client->request('GET', '/product/'.$product->getSlug());

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCustomerAreasRequireLogin(): void
    {
        foreach (['/cart', '/checkout', '/account', '/account/address', '/orders', '/wishlist'] as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseRedirects('/login', 302, $path);
        }
    }

    public function testAddToCartMergesQuantityAndEnforcesStock(): void
    {
        $product = $this->product('Hand Saw', stock: 3);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->addFromProductPage($product, '2');
        $this->addFromProductPage($product, '1');
        $this->assertSame(3, $this->cartQuantity($product));

        $this->client->request('GET', '/product/'.$product->getSlug());
        $this->client->submitForm('Add to cart', ['quantity' => '1']);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Only 3');
        $this->assertSame(3, $this->cartQuantity($product));
    }

    public function testCartItemsOfOtherUsersAreNotAccessible(): void
    {
        $other = $this->user('other@example.test');
        $item = $this->cartItem($other, $this->product('Hand Saw'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->client->request('POST', '/cart/'.$item->getId().'/remove');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testCheckoutCalculatesTotalsOnServerAndCompletesOrder(): void
    {
        $saw = $this->product('Hand Saw', price: '500.00', salePrice: '400.50', stock: 10);
        $nail = $this->product('Nails', price: '19.99', stock: 5);
        $this->cartItem($this->customer, $saw, 2);
        $this->cartItem($this->customer, $nail, 3);
        $code = $this->discount('SAVE10', 'percent', '10.00');
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->client->request('GET', '/checkout');
        $this->client->submitForm('Place order', [
            'checkout[address]' => (string) $this->address->getId(),
            'checkout[shippingMethod]' => 'express',
            'checkout[paymentMethod]' => Order::PAYMENT_COD,
            'checkout[discountCode]' => 'SAVE10',
            'checkout[notes]' => 'Leave at the gate',
        ]);

        $this->assertEmailCount(1);
        $this->assertResponseRedirects();

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy([]);
        // 2 x 400.50 + 3 x 19.99 = 860.97, less 10% (86.10), plus 250 shipping
        $this->assertSame('860.97', $order->getSubtotal());
        $this->assertSame('86.10', $order->getDiscount());
        $this->assertSame('250.00', $order->getShippingFee());
        $this->assertSame('1024.87', $order->getTotal());
        $this->assertSame(Order::STATUS_PENDING, $order->getStatus());
        $this->assertSame('Leave at the gate', $order->getNotes());
        $this->assertMatchesRegularExpression('/^JNC-\d{8}-[0-9A-F]{8}$/', $order->getOrderNumber());

        $prices = [];
        foreach ($order->getItems() as $line) {
            $prices[$line->getProduct()->getName()] = $line->getUnitPrice();
        }
        $this->assertSame(['Hand Saw' => '400.50', 'Nails' => '19.99'], $prices, 'Line items snapshot the price paid');

        $this->assertSame(8, $this->em->find(Product::class, $saw->getId())->getStock());
        $this->assertSame(2, $this->em->find(Product::class, $nail->getId())->getStock());
        $this->assertSame(0, $this->em->getRepository(CartItem::class)->count([]));
        $this->assertSame(1, $this->em->find(DiscountCode::class, $code->getId())->getTimesUsed());
        $this->assertSame(2, $this->em->getRepository(StockMovement::class)->count(['reason' => 'order']));
    }

    public function testFixedDiscountCannotExceedSubtotal(): void
    {
        $this->cartItem($this->customer, $this->product('Nails', price: '50.00'), 1);
        $this->discount('BIG', 'fixed', '500.00');
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->client->request('GET', '/checkout');
        $this->client->submitForm('Place order', [
            'checkout[shippingMethod]' => 'standard',
            'checkout[paymentMethod]' => Order::PAYMENT_COD,
            'checkout[discountCode]' => 'BIG',
        ]);

        $this->em->clear();
        $order = $this->em->getRepository(Order::class)->findOneBy([]);
        $this->assertSame('50.00', $order->getDiscount());
        $this->assertSame('100.00', $order->getTotal());
    }

    public function testInvalidDiscountCodeBlocksCheckout(): void
    {
        $product = $this->product('Nails', stock: 5);
        $this->cartItem($this->customer, $product, 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->client->request('GET', '/checkout');
        $this->client->submitForm('Place order', [
            'checkout[shippingMethod]' => 'pickup',
            'checkout[paymentMethod]' => Order::PAYMENT_COD,
            'checkout[discountCode]' => 'NOPE',
        ]);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->em->getRepository(Order::class)->count([]));
    }

    public function testShortageRollsEverythingBack(): void
    {
        $plenty = $this->product('Plenty', stock: 10);
        $short = $this->product('Short', stock: 5);
        $this->cartItem($this->customer, $plenty, 2);
        $this->cartItem($this->customer, $short, 3);
        $code = $this->discount('SAVE10', 'percent', '10.00');
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $crawler = $this->client->request('GET', '/checkout');
        $form = $crawler->selectButton('Place order')->form([
            'checkout[shippingMethod]' => 'standard',
            'checkout[paymentMethod]' => Order::PAYMENT_COD,
            'checkout[discountCode]' => 'SAVE10',
        ]);

        // Someone else buys the stock after this customer opened the checkout page.
        $this->em->getConnection()->executeStatement('UPDATE product SET stock = 1 WHERE id = ?', [$short->getId()]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/cart');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'Short');

        $this->em->clear();
        $this->assertSame(0, $this->em->getRepository(Order::class)->count([]));
        $this->assertSame(0, $this->em->getRepository(OrderItem::class)->count([]));
        $this->assertSame(0, $this->em->getRepository(StockMovement::class)->count([]));
        $this->assertSame(10, $this->em->find(Product::class, $plenty->getId())->getStock(), 'Earlier deduction is undone');
        $this->assertSame(1, $this->em->find(Product::class, $short->getId())->getStock());
        $this->assertSame(0, $this->em->find(DiscountCode::class, $code->getId())->getTimesUsed());
        $this->assertSame(2, $this->em->getRepository(CartItem::class)->count([]), 'The cart is kept');
    }

    public function testCheckoutRejectsAnotherUsersAddress(): void
    {
        $other = $this->user('other@example.test');
        $foreign = $this->address($other);
        $this->cartItem($this->customer, $this->product('Nails'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $crawler = $this->client->request('GET', '/checkout');
        $form = $crawler->selectButton('Place order')->form();
        $values = $form->getPhpValues();
        $values['checkout']['address'] = (string) $foreign->getId();
        $this->client->request('POST', '/checkout', $values);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->em->getRepository(Order::class)->count([]));
    }

    public function testGcashRequiresValidPaymentProof(): void
    {
        $this->cartItem($this->customer, $this->product('Nails'), 1);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->client->request('GET', '/checkout');
        $this->client->submitForm('Place order', [
            'checkout[shippingMethod]' => 'standard',
            'checkout[paymentMethod]' => Order::PAYMENT_GCASH,
        ]);
        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, $this->em->getRepository(Order::class)->count([]));

        $path = tempnam(sys_get_temp_dir(), 'proof');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $this->client->request('GET', '/checkout');
        $this->client->submitForm('Place order', [
            'checkout[shippingMethod]' => 'standard',
            'checkout[paymentMethod]' => Order::PAYMENT_GCASH,
            'checkout[paymentProof]' => new UploadedFile($path, 'receipt.png', 'image/png', null, true),
        ]);
        $this->assertResponseRedirects();

        $this->em->clear();
        $proof = $this->em->getRepository(Order::class)->findOneBy([])->getPaymentProof();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $proof);
        unlink(static::getContainer()->getParameter('kernel.project_dir').'/public/uploads/payment_proofs/'.$proof);
    }

    public function testCustomerCancelRestoresStockOnce(): void
    {
        $product = $this->product('Hand Saw', stock: 4);
        $order = $this->order($this->customer, $product, 3);
        $this->em->flush();
        $id = $order->getId();
        $this->client->loginUser($this->customer);

        $this->client->request('GET', '/orders/'.$id);
        $crawler = $this->client->submitForm('Cancel order', ['reason' => 'Ordered by mistake']);

        $this->em->clear();
        $saved = $this->em->find(Order::class, $id);
        $this->assertSame(Order::STATUS_CANCELLED, $saved->getStatus());
        $this->assertSame('Ordered by mistake', $saved->getCancelReason());
        $this->assertSame(7, $this->em->find(Product::class, $product->getId())->getStock());

        $movement = $this->em->getRepository(StockMovement::class)->findOneBy(['reason' => 'order']);
        $this->assertSame(3, $movement->getQuantityChange());

        // Replaying the request cannot return the stock a second time.
        $this->client->request('POST', '/orders/'.$id.'/cancel');
        $this->em->clear();
        $this->assertSame(7, $this->em->find(Product::class, $product->getId())->getStock());
    }

    public function testAdminCancelRestoresStock(): void
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $product = $this->product('Hand Saw', stock: 4);
        $order = $this->order($this->customer, $product, 3);
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/order/'.$order->getId());
        $this->client->submitForm('Cancel order', ['reason' => 'Out of service area']);

        $this->em->clear();
        $this->assertSame(7, $this->em->find(Product::class, $product->getId())->getStock());
    }

    public function testCustomerCannotCancelOnceConfirmed(): void
    {
        $order = $this->order($this->customer, $this->product('Hand Saw'), 1, Order::STATUS_CONFIRMED);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $crawler = $this->client->request('GET', '/orders/'.$order->getId());

        $this->assertCount(0, $crawler->selectButton('Cancel order'));
    }

    public function testOrdersOfOtherCustomersAreHidden(): void
    {
        $other = $this->user('other@example.test');
        $order = $this->order($other, $this->product('Hand Saw'), 1);
        $this->em->flush();
        $number = $order->getOrderNumber();
        $this->client->loginUser($this->customer);

        $this->client->request('GET', '/orders/'.$order->getId());
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('POST', '/orders/'.$order->getId().'/cancel');
        $this->assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/orders');
        $this->assertStringNotContainsString($number, $this->client->getResponse()->getContent());
    }

    public function testStatusChangeEmailsTheCustomer(): void
    {
        $admin = $this->user('admin@example.test', ['ROLE_ADMIN']);
        $order = $this->order($this->customer, $this->product('Hand Saw'), 1);
        $this->em->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/order/'.$order->getId());
        $this->client->submitForm('Save', ['order_status[status]' => Order::STATUS_CONFIRMED]);

        $this->assertEmailCount(1);
        $this->assertEmailHeaderSame($this->getMailerMessage(), 'To', 'customer@example.test');
        $this->assertEmailTextBodyContains($this->getMailerMessage(), 'confirmed');
    }

    public function testOnlyBuyersWhoReceivedProductCanReviewAndOnlyOnce(): void
    {
        $product = $this->product('Hand Saw');
        $this->em->flush();
        $path = '/product/'.$product->getSlug();
        $this->client->loginUser($this->customer);

        $crawler = $this->client->request('GET', $path);
        $this->assertCount(0, $crawler->selectButton('Submit review'));
        $this->client->request('POST', $path, ['review' => ['rating' => '5', 'comment' => 'Fake']]);
        $this->assertResponseStatusCodeSame(403);

        $this->em->clear();
        $this->customer = $this->em->find(User::class, $this->customer->getId());
        $product = $this->em->find(Product::class, $product->getId());
        $this->order($this->customer, $product, 1, Order::STATUS_DELIVERED);
        $this->em->flush();

        $this->client->request('GET', $path);
        $this->client->submitForm('Submit review', ['review[rating]' => '4', 'review[comment]' => 'Sharp and solid']);
        $this->assertResponseRedirects($path);

        $this->em->clear();
        $review = $this->em->getRepository(Review::class)->findOneBy([]);
        $this->assertSame(4, $review->getRating());
        $this->assertFalse($review->isApproved());

        $crawler = $this->client->request('GET', $path);
        $this->assertCount(0, $crawler->selectButton('Submit review'));
        $this->assertStringNotContainsString('Sharp and solid', $this->client->getResponse()->getContent(), 'Hidden until approved');
    }

    public function testAddressesArePrivateToTheirOwner(): void
    {
        $other = $this->user('other@example.test');
        $foreign = $this->address($other);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        foreach (['/account/address/'.$foreign->getId(), '/account/address/'.$foreign->getId().'/edit'] as $path) {
            $this->client->request('GET', $path);
            $this->assertResponseStatusCodeSame(403, $path);
        }
        $this->client->request('POST', '/account/address/'.$foreign->getId());
        $this->assertResponseStatusCodeSame(403);
    }

    public function testNewAddressBelongsToUserAndKeepsSingleDefault(): void
    {
        $this->address->setIsDefault(true);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->client->request('GET', '/account/address/new');
        $this->client->submitForm('Save', [
            'address[label]' => 'Office',
            'address[recipient]' => 'Juan',
            'address[phone]' => '0917',
            'address[line1]' => '1 Main St',
            'address[city]' => 'Manila',
            'address[province]' => 'Metro Manila',
            'address[postalCode]' => '1000',
            'address[isDefault]' => true,
        ]);
        $this->assertResponseRedirects('/account/address');

        $this->em->clear();
        $defaults = $this->em->getRepository(Address::class)->findBy(['isDefault' => true]);
        $this->assertCount(1, $defaults);
        $this->assertSame('Office', $defaults[0]->getLabel());
        $this->assertSame($this->customer->getId(), $defaults[0]->getOwner()->getId());
    }

    public function testAddressUsedByAnOrderCannotBeEditedOrDeleted(): void
    {
        $this->order($this->customer, $this->product('Hand Saw'), 1);
        $this->em->flush();
        $id = $this->address->getId();
        $this->client->loginUser($this->customer);

        $crawler = $this->client->request('GET', '/account/address/'.$id);
        $this->client->submitForm('Delete');
        $this->client->followRedirect();
        $this->assertSelectorTextContains('.flash-error', 'cannot be deleted');

        $this->client->request('GET', '/account/address/'.$id.'/edit');
        $this->assertResponseRedirects('/account/address');

        $this->em->clear();
        $this->assertNotNull($this->em->find(Address::class, $id));
    }

    public function testProfileUpdateCannotChangeRolesOrEmail(): void
    {
        $this->client->loginUser($this->customer);

        $crawler = $this->client->request('GET', '/account');
        $form = $crawler->selectButton('Save profile')->form();
        $values = $form->getPhpValues();
        $values['profile']['fullName'] = 'New Name';
        $values['profile']['roles'] = ['ROLE_ADMIN'];
        $this->client->request('POST', '/account', $values);
        $this->assertResponseStatusCodeSame(422, 'Extra fields are rejected');

        $this->client->request('GET', '/account');
        $this->client->submitForm('Save profile', ['profile[fullName]' => 'New Name']);

        $this->em->clear();
        $saved = $this->em->find(User::class, $this->customer->getId());
        $this->assertSame('New Name', $saved->getFullName());
        $this->assertSame(['ROLE_USER'], $saved->getRoles());
    }

    public function testWishlistAddsOnceAndIsPrivate(): void
    {
        $product = $this->product('Hand Saw');
        $other = $this->user('other@example.test');
        $foreign = (new WishlistItem())->setOwner($other)->setProduct($product)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($foreign);
        $this->em->flush();
        $this->client->loginUser($this->customer);

        $this->client->request('GET', '/product/'.$product->getSlug());
        $this->client->submitForm('Add to wishlist');
        $this->assertResponseRedirects('/wishlist');

        $crawler = $this->client->request('GET', '/product/'.$product->getSlug());
        $this->assertCount(0, $crawler->selectButton('Add to wishlist'));

        $this->client->request('POST', '/wishlist/'.$foreign->getId().'/remove');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testContactFormCreatesNewInquiry(): void
    {
        $this->client->request('GET', '/contact');
        $this->client->submitForm('Send message', [
            'inquiry[name]' => 'Ana',
            'inquiry[email]' => 'ana@example.test',
            'inquiry[subject]' => 'Bulk price',
            'inquiry[message]' => 'Do you deliver to Cavite?',
        ]);
        $this->assertResponseRedirects('/contact');

        $inquiry = $this->em->getRepository(Inquiry::class)->findOneBy([]);
        $this->assertSame(Inquiry::STATUS_NEW, $inquiry->getStatus());
        $this->assertNotNull($inquiry->getCreatedAt());
    }

    public function testContactFormRejectsBadEmail(): void
    {
        $this->client->request('GET', '/contact');
        $this->client->submitForm('Send message', [
            'inquiry[name]' => 'Ana',
            'inquiry[email]' => 'not-an-email',
            'inquiry[subject]' => 'Hello',
            'inquiry[message]' => 'Hello',
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testMaterialCalculator(): void
    {
        $this->client->request('GET', '/tools/material-calculator?type=paint&area=25&coats=2');
        $this->assertSelectorTextContains('#result', '5 liters');

        // 10 sqm of 30x30cm tiles is 111.1 tiles, or 123 with 10% extra.
        $this->client->request('GET', '/tools/material-calculator?type=tile&area=10&tile_length=30&tile_width=30');
        $this->assertSelectorTextContains('#result', '123 tiles');

        $this->client->request('GET', '/tools/material-calculator?type=lumber&total_length=25&piece_length=3');
        $this->assertSelectorTextContains('#result', '9 pieces');

        $this->client->request('GET', '/tools/material-calculator?type=paint&area=-1');
        $this->assertSelectorTextContains('#result', 'greater than zero');
    }

    private function addFromProductPage(Product $product, string $quantity): void
    {
        $this->client->request('GET', '/product/'.$product->getSlug());
        $this->client->submitForm('Add to cart', ['quantity' => $quantity]);
    }

    private function cartQuantity(Product $product): int
    {
        $this->em->clear();

        return $this->em->getRepository(CartItem::class)->findOneBy(['product' => $product->getId()])->getQuantity();
    }

    private function purge(): void
    {
        $this->em->clear();
        foreach ([
            OrderItem::class, Order::class, CartItem::class, WishlistItem::class, Review::class,
            StockMovement::class, Address::class, DiscountCode::class, Product::class, Brand::class,
            Inquiry::class, User::class,
        ] as $class) {
            $this->em->createQuery('DELETE FROM '.$class)->execute();
        }
        $this->em->getConnection()->executeStatement('UPDATE category SET parent_id = NULL');
        $this->em->createQuery('DELETE FROM '.Category::class)->execute();
    }

    /** @param list<string> $roles */
    private function user(string $email, array $roles = []): User
    {
        $user = (new User())->setEmail($email)->setFullName('Test User')->setRoles($roles)->setPassword('hash')
            ->setIsActive(true)->setIsVerified(true)->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($user);

        return $user;
    }

    private function address(User $owner): Address
    {
        $address = (new Address())->setLabel('Home')->setRecipient('Recipient')->setPhone('0917')->setLine1('1 Street')
            ->setCity('Manila')->setProvince('Metro Manila')->setPostalCode('1000')->setIsDefault(false)->setOwner($owner);
        $this->em->persist($address);

        return $address;
    }

    private function product(string $name, string $price = '100.00', ?string $salePrice = null, int $stock = 10, string $status = Product::STATUS_ACTIVE): Product
    {
        $id = ++$this->sequence;
        $category = (new Category())->setName('Category '.$id)->setSlug('category-'.$id)->setDisplayOrder(0)->setIsActive(true);
        $this->em->persist($category);

        $product = (new Product())->setName($name)->setSku('SKU-'.$id)->setSlug('product-'.$id)->setDescription('Description')
            ->setPrice($price)->setCostPrice('1.00')->setSalePrice($salePrice)->setUnit('pc')->setStock($stock)->setLowStockThreshold(1)
            ->setStatus($status)->setIsFeatured(false)->setCreatedAt(new \DateTimeImmutable())->setCategory($category);
        $this->em->persist($product);

        return $product;
    }

    private function cartItem(User $owner, Product $product, int $quantity): CartItem
    {
        $item = (new CartItem())->setOwner($owner)->setProduct($product)->setQuantity($quantity)
            ->setCreatedAt(new \DateTimeImmutable())->setUpdatedAt(new \DateTimeImmutable());
        $this->em->persist($item);

        return $item;
    }

    private function discount(string $code, string $type, string $value): DiscountCode
    {
        $discount = (new DiscountCode())->setCode($code)->setType($type)->setValue($value)->setTimesUsed(0)->setIsActive(true);
        $this->em->persist($discount);

        return $discount;
    }

    private function order(User $customer, Product $product, int $quantity, string $status = Order::STATUS_PENDING): Order
    {
        $address = $customer === $this->customer ? $this->em->getRepository(Address::class)->findOneBy(['owner' => $customer]) ?? $this->address : $this->address($customer);
        $order = (new Order())->setOrderNumber('ORD-'.++$this->sequence)->setStatus($status)->setPaymentMethod(Order::PAYMENT_COD)
            ->setPaymentStatus(Order::PAYMENT_UNPAID)->setShippingMethod('standard')->setSubtotal('100.00')->setShippingFee('0.00')
            ->setDiscount('0.00')->setTotal('100.00')->setCreatedAt(new \DateTimeImmutable())
            ->setCustomer($customer)->setShippingAddress($address);
        $line = (new OrderItem())->setProduct($product)->setQuantity($quantity)->setUnitPrice('100.00');
        $order->addItem($line);
        $this->em->persist($order);
        $this->em->persist($line);

        return $order;
    }
}
