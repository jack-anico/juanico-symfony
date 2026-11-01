<?php

namespace App\Controller;

use Knp\Component\Pager\PaginatorInterface;
use App\Form\ProductFilterType;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class CatalogController extends AbstractController
{
    #[Route('/catalog', name: 'app_catalog', methods: ['GET'])]
    public function index(Request $request, ProductRepository $products, FormFactoryInterface $formFactory, PaginatorInterface $paginator): Response
    {
        // An unnamed form keeps the URLs short, such as /catalog?q=saw&sort=name
        $form = $formFactory->createNamed('', ProductFilterType::class);
        $form->handleRequest($request);

        $filters = $form->isSubmitted() && $form->isValid() ? $form->getData() : [];
        $deals = $request->query->getBoolean('deals');

        return $this->render('catalog/index.html.twig', [
            'form' => $form,
            'deals' => $deals,
            'products' => $paginator->paginate($products->search(
                $filters['q'] ?? null,
                ($filters['category'] ?? null)?->getId(),
                ($filters['brand'] ?? null)?->getId(),
                $filters['minPrice'] ?? null,
                $filters['maxPrice'] ?? null,
                $filters['sort'] ?? null,
                $deals,
            ), max(1, $request->query->getInt('page', 1)), 20, ['wrap-queries' => true]),
        ]);
    }
}
