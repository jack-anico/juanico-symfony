<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReviewController extends AbstractController
{
    #[Route('/admin/review', name: 'app_admin_review')]
    public function index(): Response
    {
        return $this->render('admin/review/index.html.twig', [
            'controller_name' => 'ReviewController',
        ]);
    }
}
