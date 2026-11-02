<?php

namespace App\Command;

use App\Entity\Brand;
use App\Entity\Category;
use App\Entity\Product;
use App\Repository\BrandRepository;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:catalog:import',
    description: 'Adds or updates brands, categories and products from CSV files. Safe to run again.',
)]
class CatalogImportCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private BrandRepository $brands,
        private CategoryRepository $categories,
        private ProductRepository $products,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folder holding brands.csv, categories.csv and products.csv')] string $path = 'project_documentation/products_documentation',
        #[Option('Hide products and categories that are not in the files. Nothing is deleted, so past orders keep their products.')] bool $retireOthers = false,
    ): int {
        foreach (['brands', 'categories', 'products'] as $file) {
            if (!is_readable("$path/$file.csv")) {
                $io->error("Cannot read $path/$file.csv");

                return Command::FAILURE;
            }
        }

        $counts = $this->em->wrapInTransaction(function () use ($path, $retireOthers): array {
            $brands = $this->importBrands($this->rows("$path/brands.csv"));
            $categories = $this->importCategories($this->rows("$path/categories.csv"));
            $counts = $this->importProducts($this->rows("$path/products.csv"), $brands, $categories);

            $counts['retired products'] = 0;
            $counts['retired categories'] = 0;
            if ($retireOthers) {
                $counts['retired products'] = $this->retireProducts($counts['skus']);
                $counts['retired categories'] = $this->retireCategories($categories);
            }
            unset($counts['skus']);

            return $counts;
        });

        $io->success(sprintf(
            '%d products added, %d updated. %d products and %d categories hidden.',
            $counts['added'],
            $counts['updated'],
            $counts['retired products'],
            $counts['retired categories'],
        ));

        return Command::SUCCESS;
    }

    /**
     * @param array<array<string, string>> $rows
     *
     * @return array<string, Brand> By name
     */
    private function importBrands(array $rows): array
    {
        $brands = [];
        foreach ($this->brands->findAll() as $brand) {
            $brands[$brand->getName()] = $brand;
        }

        foreach ($rows as $row) {
            if (!isset($brands[$row['name']])) {
                $brands[$row['name']] = (new Brand())->setName($row['name']);
                $this->em->persist($brands[$row['name']]);
            }
            $brands[$row['name']]->setIsActive(true);
        }

        return $brands;
    }

    /**
     * @param array<array<string, string>> $rows
     *
     * @return array<string, Category> The categories in the file, by slug
     */
    private function importCategories(array $rows): array
    {
        $existing = [];
        foreach ($this->categories->findAll() as $category) {
            $existing[$category->getSlug()] = $category;
        }

        $categories = [];
        foreach ($rows as $row) {
            $category = $existing[$row['slug']] ?? (new Category())->setSlug($row['slug']);
            $category->setName($row['name'])->setDisplayOrder((int) $row['displayOrder'])->setIsActive(true);
            $this->em->persist($category);
            $categories[$row['slug']] = $category;
        }

        // A second pass, so a parent may appear after its children in the file
        foreach ($rows as $row) {
            $parent = '' === $row['parent'] ? null : ($categories[$row['parent']] ?? throw new \RuntimeException("Unknown parent category \"{$row['parent']}\""));
            $categories[$row['slug']]->setParent($parent);
        }

        return $categories;
    }

    /**
     * @param array<array<string, string>> $rows
     * @param array<string, Brand>         $brands
     * @param array<string, Category>      $categories
     *
     * @return array{added: int, updated: int, skus: array<string, true>}
     */
    private function importProducts(array $rows, array $brands, array $categories): array
    {
        $existing = [];
        foreach ($this->products->findAll() as $product) {
            $existing[$product->getSku()] = $product;
        }

        $counts = ['added' => 0, 'updated' => 0, 'skus' => []];
        foreach ($rows as $row) {
            $product = $existing[$row['sku']] ?? null;
            if (null === $product) {
                // Stock is only set once: running the import again must not undo sales or restocks
                $product = (new Product())->setSku($row['sku'])->setStock((int) $row['stock'])->setCreatedAt(new \DateTimeImmutable());
                $this->em->persist($product);
                ++$counts['added'];
            } else {
                ++$counts['updated'];
            }

            $product->setName($row['name'])
                ->setSlug($row['slug'])
                ->setDescription($row['description'])
                ->setPrice($row['price'])
                ->setCostPrice($row['costPrice'])
                ->setSalePrice('' === $row['salePrice'] ? null : $row['salePrice'])
                ->setUnit($row['unit'])
                ->setLowStockThreshold((int) $row['lowStockThreshold'])
                ->setStatus($row['status'])
                ->setIsFeatured('1' === $row['isFeatured'])
                ->setSpecifications('' === $row['specifications'] ? null : json_decode($row['specifications'], true, 8, \JSON_THROW_ON_ERROR))
                ->setImage('' === $row['image'] ? null : $row['image'])
                ->setBrand('' === $row['brand'] ? null : ($brands[$row['brand']] ?? throw new \RuntimeException("Unknown brand \"{$row['brand']}\" on {$row['sku']}")))
                ->setCategory($categories[$row['category_slug']] ?? throw new \RuntimeException("Unknown category \"{$row['category_slug']}\" on {$row['sku']}"));

            $counts['skus'][$row['sku']] = true;
        }

        return $counts;
    }

    /** @param array<string, true> $skus */
    private function retireProducts(array $skus): int
    {
        $retired = 0;
        foreach ($this->products->findAll() as $product) {
            if (!isset($skus[$product->getSku()]) && Product::STATUS_ARCHIVED !== $product->getStatus()) {
                $product->setStatus(Product::STATUS_ARCHIVED);
                ++$retired;
            }
        }

        return $retired;
    }

    /** @param array<string, Category> $keep */
    private function retireCategories(array $keep): int
    {
        $retired = 0;
        foreach ($this->categories->findAll() as $category) {
            if (!isset($keep[$category->getSlug()]) && $category->isActive()) {
                $category->setIsActive(false);
                ++$retired;
            }
        }

        return $retired;
    }

    /** @return array<array<string, string>> */
    private function rows(string $file): array
    {
        $handle = fopen($file, 'r');
        $header = fgetcsv($handle, escape: '');
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);

        $rows = [];
        while (false !== $row = fgetcsv($handle, escape: '')) {
            if ([null] !== $row) {
                $rows[] = array_combine($header, $row);
            }
        }
        fclose($handle);

        return $rows;
    }
}
