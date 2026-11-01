<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    /** The dropdown under the search box. Very short searches return nothing because they match almost everything. */
    #[Route('/search/suggest', name: 'app_search_suggest', methods: ['GET'])]
    public function suggest(Request $request, ProductRepository $products, CategoryRepository $categories): Response
    {
        $q = trim($request->query->getString('q'));
        if (mb_strlen($q) < 2) {
            return new Response('');
        }

        $matches = $products->suggest($q);

        return $this->render('search/_suggestions.html.twig', [
            'q' => $q,
            'products' => $matches,
            'categories' => $categories->findActiveMatching($q),
            'correction' => $matches ? null : $products->correctSpelling($q),
        ]);
    }
}
