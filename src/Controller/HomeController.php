<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(ProductRepository $products): Response
    {
        return $this->render('home/index.html.twig', [
            'featured' => $products->findFeatured(),
            'on_sale' => $products->findOnSale(),
            'product_count' => $products->countActive(),
        ]);
    }
}
