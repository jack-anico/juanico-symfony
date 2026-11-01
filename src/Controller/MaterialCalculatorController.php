<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MaterialCalculatorController extends AbstractController
{
    #[Route('/material/calculator', name: 'app_material_calculator')]
    public function index(): Response
    {
        return $this->render('material_calculator/index.html.twig', [
            'controller_name' => 'MaterialCalculatorController',
        ]);
    }
}
