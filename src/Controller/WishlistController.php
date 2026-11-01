<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class WishlistController extends AbstractController
{
    #[Route('/wishlist', name: 'app_wishlist')]
    public function index(): Response
    {
        return $this->render('wishlist/index.html.twig', [
            'controller_name' => 'WishlistController',
        ]);
    }
}
