<?php

namespace App\Controller\Admin;

use Knp\Component\Pager\PaginatorInterface;
use App\Entity\Product;
use App\Entity\StockMovement;
use App\Entity\User;
use App\Form\ProductType;
use App\Form\StockAdjustmentType;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/product')]
final class ProductController extends AbstractController
{
    #[Route(name: 'app_admin_product_index', methods: ['GET'])]
    public function index(Request $request, ProductRepository $productRepository, PaginatorInterface $paginator): Response
    {
        return $this->render('admin/product/index.html.twig', [
            'products' => $paginator->paginate($productRepository->adminListQuery(), max(1, $request->query->getInt('page', 1)), 20),
        ]);
    }

    #[Route('/new', name: 'app_admin_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $product = new Product();
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $product->setCreatedAt(new \DateTimeImmutable());
            $this->storeUploadedImage($form, $product);
            $entityManager->persist($product);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_product_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/product/new.html.twig', [
            'product' => $product,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_admin_product_show', methods: ['GET'])]
    public function show(Product $product): Response
    {
        return $this->render('admin/product/show.html.twig', [
            'product' => $product,
            'stock_form' => $this->createStockForm($product),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_admin_product_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Product $product, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ProductType::class, $product);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->storeUploadedImage($form, $product);
            $entityManager->flush();

            return $this->redirectToRoute('app_admin_product_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/product/edit.html.twig', [
            'product' => $product,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/stock', name: 'app_admin_product_stock', methods: ['POST'])]
    public function adjustStock(Request $request, Product $product, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createStockForm($product);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'The stock adjustment was not valid.');

            return $this->redirectToRoute('app_admin_product_show', ['id' => $product->getId()], Response::HTTP_SEE_OTHER);
        }

        $data = $form->getData();
        /** @var User $admin */
        $admin = $this->getUser();

        // Lock the row so concurrent checkouts or adjustments cannot push stock below zero.
        $applied = $entityManager->wrapInTransaction(function () use ($entityManager, $product, $data, $admin): bool {
            $entityManager->refresh($product, LockMode::PESSIMISTIC_WRITE);

            $newStock = $product->getStock() + $data['quantityChange'];
            if ($newStock < 0) {
                return false;
            }

            $product->setStock($newStock);
            $entityManager->persist((new StockMovement())
                ->setProduct($product)
                ->setAdmin($admin)
                ->setQuantityChange($data['quantityChange'])
                ->setReason($data['reason'])
                ->setNote($data['note'])
                ->setCreatedAt(new \DateTimeImmutable()));

            return true;
        });

        if ($applied) {
            $this->addFlash('success', 'Stock updated.');
        } else {
            $this->addFlash('error', 'Stock cannot go below zero.');
        }

        return $this->redirectToRoute('app_admin_product_show', ['id' => $product->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}', name: 'app_admin_product_delete', methods: ['POST'])]
    public function delete(Request $request, Product $product, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$product->getId(), $request->getPayload()->getString('_token'))) {
            try {
                $image = $product->getImage();
                $entityManager->remove($product);
                $entityManager->flush();
                $this->removeImage($image);
            } catch (ForeignKeyConstraintViolationException) {
                $this->addFlash('error', 'This product is used by orders, carts or reviews. Archive it instead.');
            }
        }

        return $this->redirectToRoute('app_admin_product_index', [], Response::HTTP_SEE_OTHER);
    }

    private function createStockForm(Product $product): FormInterface
    {
        return $this->createForm(StockAdjustmentType::class, null, [
            'action' => $this->generateUrl('app_admin_product_stock', ['id' => $product->getId()]),
        ]);
    }

    private function storeUploadedImage(FormInterface $form, Product $product): void
    {
        /** @var UploadedFile|null $file */
        $file = $form->get('imageFile')->getData();
        if (null === $file) {
            return;
        }

        // The name is random and the extension comes from the file's content, never from user input.
        $filename = bin2hex(random_bytes(16)).'.'.$file->guessExtension();
        $file->move($this->uploadDirectory(), $filename);

        $this->removeImage($product->getImage());
        $product->setImage($filename);
    }

    private function removeImage(?string $filename): void
    {
        if (null !== $filename && is_file($path = $this->uploadDirectory().'/'.$filename)) {
            unlink($path);
        }
    }

    private function uploadDirectory(): string
    {
        return $this->getParameter('kernel.project_dir').'/public/uploads/products';
    }
}
