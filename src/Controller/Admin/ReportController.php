<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends AbstractController
{
    #[Route('/admin/report', name: 'app_admin_report')]
    public function index(): Response
    {
        return $this->render('admin/report/index.html.twig', [
            'controller_name' => 'ReportController',
        ]);
    }
}
