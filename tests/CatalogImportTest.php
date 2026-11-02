<?php

namespace App\Tests;

use App\Entity\Brand;
use App\Entity\CartItem;
use App\Entity\Category;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class CatalogImportTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $command;
    private string $folder;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->command = new CommandTester((new Application($kernel))->find('app:catalog:import'));
        $this->purge();

        $this->folder = sys_get_temp_dir().'/catalog-'.uniqid();
        mkdir($this->folder);
        file_put_contents($this->folder.'/brands.csv', "name\nStanley\n");
        // The child comes first to prove file order does not matter
        file_put_contents($this->folder.'/categories.csv', "name,slug,parent,displayOrder\nHammers,hammers,hand-tools,1\nHand Tools,hand-tools,,1\n");
        file_put_contents($this->folder.'/products.csv', implode("\n", [
            'sku,name,slug,brand,category_parent,category,category_slug,unit,price,costPrice,salePrice,stock,lowStockThreshold,status,isFeatured,specifications,description,image',
            'JNC-1,Claw Hammer,claw-hammer,Stanley,Hand Tools,Hammers,hammers,pc,385.00,255.00,350.00,88,10,active,1,"{""weight"": ""16oz""}","Strong, reliable hammer.",claw-hammer.jpg',
        ])."\n");
    }

    protected function tearDown(): void
    {
        $this->purge();
        array_map('unlink', glob($this->folder.'/*'));
        rmdir($this->folder);
        parent::tearDown();
    }

    public function testImportsEveryField(): void
    {
        $this->command->execute(['path' => $this->folder]);
        $this->command->assertCommandIsSuccessful();

        $product = $this->em->getRepository(Product::class)->findOneBy(['sku' => 'JNC-1']);
        $this->assertSame('Claw Hammer', $product->getName());
        $this->assertSame('350.00', $product->getSalePrice());
        $this->assertSame(88, $product->getStock());
        $this->assertTrue($product->isFeatured());
        $this->assertSame(['weight' => '16oz'], $product->getSpecifications());
        $this->assertSame('Strong, reliable hammer.', $product->getDescription());
        $this->assertSame('claw-hammer.jpg', $product->getImage());
        $this->assertSame('Stanley', $product->getBrand()->getName());
        $this->assertSame('Hammers', $product->getCategory()->getName());
        $this->assertSame('hand-tools', $product->getCategory()->getParent()->getSlug());
    }

    public function testRunningAgainUpdatesWithoutDuplicatesOrResettingStock(): void
    {
        $this->command->execute(['path' => $this->folder]);
        $product = $this->em->getRepository(Product::class)->findOneBy(['sku' => 'JNC-1']);
        $product->setStock(5)->setName('Renamed');
        $this->em->flush();

        $this->command->execute(['path' => $this->folder]);
        $this->command->assertCommandIsSuccessful();
        $this->em->clear();

        $this->assertSame(1, $this->em->getRepository(Product::class)->count([]));
        $this->assertSame(1, $this->em->getRepository(Brand::class)->count([]));
        $this->assertSame(2, $this->em->getRepository(Category::class)->count([]));
        $product = $this->em->getRepository(Product::class)->findOneBy(['sku' => 'JNC-1']);
        $this->assertSame('Claw Hammer', $product->getName());
        $this->assertSame(5, $product->getStock());
    }

    public function testRetireOthersHidesInsteadOfDeleting(): void
    {
        $old = (new Category())->setName('Old')->setSlug('old')->setDisplayOrder(0)->setIsActive(true);
        $this->em->persist($old);
        $this->em->persist((new Product())->setName('Demo')->setSku('DEMO')->setSlug('demo')->setDescription('d')
            ->setPrice('1.00')->setCostPrice('1.00')->setUnit('pc')->setStock(1)->setLowStockThreshold(0)
            ->setStatus(Product::STATUS_ACTIVE)->setIsFeatured(false)->setCreatedAt(new \DateTimeImmutable())->setCategory($old));
        $this->em->flush();

        $this->command->execute(['path' => $this->folder]);
        $this->em->clear();
        $this->assertSame(Product::STATUS_ACTIVE, $this->em->getRepository(Product::class)->findOneBy(['sku' => 'DEMO'])->getStatus(), 'Only on request');

        $this->command->execute(['path' => $this->folder, '--retire-others' => true]);
        $this->em->clear();

        $this->assertSame(Product::STATUS_ARCHIVED, $this->em->getRepository(Product::class)->findOneBy(['sku' => 'DEMO'])->getStatus());
        $this->assertFalse($this->em->getRepository(Category::class)->findOneBy(['slug' => 'old'])->isActive());
        $this->assertSame(Product::STATUS_ACTIVE, $this->em->getRepository(Product::class)->findOneBy(['sku' => 'JNC-1'])->getStatus());
    }

    public function testMissingFileFailsCleanly(): void
    {
        unlink($this->folder.'/brands.csv');

        $this->assertSame(1, $this->command->execute(['path' => $this->folder]));
        $this->assertSame(0, $this->em->getRepository(Product::class)->count([]));
    }

    private function purge(): void
    {
        $this->em->clear();
        $this->em->createQuery('DELETE FROM '.CartItem::class)->execute();
        $this->em->createQuery('UPDATE '.Category::class.' c SET c.parent = NULL')->execute();
        foreach ([Product::class, Brand::class, Category::class] as $class) {
            $this->em->createQuery('DELETE FROM '.$class)->execute();
        }
    }
}
