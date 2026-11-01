<?php

namespace App\Tests;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SearchTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ProductRepository $products;
    private Category $category;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->products = static::getContainer()->get(ProductRepository::class);
        $this->purge();
        $this->category = (new Category())->setName('Hand Tools')->setSlug('hand-tools')->setDisplayOrder(0)->setIsActive(true);
        $this->em->persist($this->category);
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    public function testWordsCanMatchAcrossNameAndBrandInAnyOrder(): void
    {
        $stanley = (new Brand())->setName('Stanley')->setIsActive(true);
        $this->em->persist($stanley);
        $this->product('Claw Hammer 16oz', brand: $stanley);
        $this->product('Rubber Mallet', brand: $stanley);
        $this->product('Ball Peen Hammer');
        $this->em->flush();

        $this->assertSame(['Claw Hammer 16oz'], $this->names('stanley hammer'));
        $this->assertSame(['Claw Hammer 16oz'], $this->names('HAMMER stanley'));
    }

    public function testParentCategoryAndSkuMatch(): void
    {
        $child = (new Category())->setName('Saws')->setSlug('saws')->setDisplayOrder(0)->setIsActive(true)->setParent($this->category);
        $this->em->persist($child);
        $this->product('Crosscut Blade', category: $child, sku: 'JNC-HT-777');
        $this->em->flush();

        $this->assertSame(['Crosscut Blade'], $this->names('hand tools'));
        $this->assertSame(['Crosscut Blade'], $this->names('jnc-ht-777'));
    }

    public function testPluralFindsSingular(): void
    {
        $this->product('Claw Hammer');
        $this->product('Brass Hinge');
        $this->em->flush();

        $this->assertSame(['Claw Hammer'], $this->names('hammers'));
        $this->assertSame(['Brass Hinge'], $this->names('brass'), 'A double s is not a plural');
    }

    public function testNameMatchesRankAboveDescriptionMatches(): void
    {
        $this->product('Nail Set', description: 'Works well with any hammer');
        $this->product('Mini Hammer');
        $this->product('Hammer Drill');
        $this->em->flush();

        $this->assertSame(['Hammer Drill', 'Mini Hammer', 'Nail Set'], $this->names('hammer'));
    }

    public function testChosenSortOverridesRelevance(): void
    {
        $this->product('Nail Set', price: '10.00', description: 'Works well with any hammer');
        $this->product('Hammer Drill', price: '900.00');
        $this->em->flush();

        $this->assertSame(['Nail Set', 'Hammer Drill'], array_map(
            static fn (Product $p) => $p->getName(),
            $this->products->search('hammer', sort: 'price_asc')->getResult(),
        ));
    }

    public function testWildcardsAreLiteralAndArchivedProductsAreHidden(): void
    {
        $this->product('Claw Hammer');
        $this->product('Old Hammer', status: Product::STATUS_ARCHIVED);
        $this->em->flush();

        $this->assertSame([], $this->names('%'));
        $this->assertSame(['Claw Hammer'], $this->names('hammer'));
        $this->assertSame(['Claw Hammer'], array_map(static fn (Product $p) => $p->getName(), $this->products->suggest('hammer')));
    }

    public function testResultsPageRanksAndPaginates(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            $this->product(sprintf('Saw %02d', $i));
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', '/catalog?q=saw');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Results for "saw"');
        $this->assertCount(20, $crawler->filter('article'));
        $this->assertStringContainsString('Saw 01', $crawler->filter('article')->first()->text());

        $crawler = $this->client->request('GET', '/catalog?q=saw&page=2');
        $this->assertCount(5, $crawler->filter('article'));
        $this->assertStringContainsString('Saw 21', $crawler->filter('article')->first()->text());
    }

    public function testTypoOffersACorrection(): void
    {
        $this->product('Claw Hammer');
        $this->em->flush();

        $this->assertSame('claw hammer', $this->products->correctSpelling('claw hammr'));
        $this->assertNull($this->products->correctSpelling('zzzzzzzz'));

        $crawler = $this->client->request('GET', '/catalog?q=hammr');
        $this->assertCount(0, $crawler->filter('article'));
        $this->assertSame('/catalog?q=hammer', $crawler->filter('[data-did-you-mean] a')->attr('href'));
    }

    public function testSuggestionsListProductsAndCategoriesAndEscapeText(): void
    {
        $this->product('<b>Bold</b> Hammer');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/search/suggest?q=hammer');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('<b>Bold', (string) $this->client->getResponse()->getContent());
        $this->assertCount(2, $crawler->filter('[role=option]'), 'The product and "see all results"');

        $crawler = $this->client->request('GET', '/search/suggest?q=hand');
        $this->assertSame('Hand Tools', $crawler->filter('[role=option]')->first()->text());
    }

    public function testSuggestionsWaitForTwoCharacters(): void
    {
        $this->product('Hammer');
        $this->em->flush();

        $this->client->request('GET', '/search/suggest?q=h');

        $this->assertResponseIsSuccessful();
        $this->assertSame('', $this->client->getResponse()->getContent());
    }

    /** @return string[] */
    private function names(string $q): array
    {
        return array_map(static fn (Product $p) => $p->getName(), $this->products->search($q)->getResult());
    }

    private function product(string $name, ?Brand $brand = null, ?Category $category = null, ?string $sku = null, string $price = '100.00', string $description = 'Description', string $status = Product::STATUS_ACTIVE): Product
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
        $product = (new Product())->setName($name)->setSku($sku ?? $slug)->setSlug($slug)->setDescription($description)
            ->setPrice($price)->setCostPrice('1.00')->setUnit('pc')->setStock(10)->setLowStockThreshold(2)
            ->setStatus($status)->setIsFeatured(false)->setCreatedAt(new \DateTimeImmutable())
            ->setCategory($category ?? $this->category)->setBrand($brand);
        $this->em->persist($product);

        return $product;
    }

    private function purge(): void
    {
        $this->em->clear();
        $this->em->createQuery('UPDATE '.Category::class.' c SET c.parent = NULL')->execute();
        foreach ([Product::class, Brand::class, Category::class] as $class) {
            $this->em->createQuery('DELETE FROM '.$class)->execute();
        }
    }
}
