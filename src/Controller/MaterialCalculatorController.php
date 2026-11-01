<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class MaterialCalculatorController extends AbstractController
{
    /** Square meters one liter of paint typically covers per coat. */
    private const PAINT_COVERAGE_PER_LITER = 10;

    /** Extra tiles allowed for cuts and breakage. */
    private const TILE_WASTE_FACTOR = 1.10;

    #[Route('/tools/material-calculator', name: 'app_material_calculator', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $query = $request->query;
        $type = $query->getString('type');

        $result = match ($type) {
            'paint' => $this->paint((float) $query->get('area'), (int) $query->get('coats', '2')),
            'tile' => $this->tiles((float) $query->get('area'), (float) $query->get('tile_length'), (float) $query->get('tile_width')),
            'lumber' => $this->lumber((float) $query->get('total_length'), (float) $query->get('piece_length')),
            default => null,
        };

        return $this->render('material_calculator/index.html.twig', [
            'type' => $type,
            'result' => $result,
            'invalid' => in_array($type, ['paint', 'tile', 'lumber'], true) && null === $result,
        ]);
    }

    private function paint(float $area, int $coats): ?string
    {
        if ($area <= 0 || $coats < 1) {
            return null;
        }

        return sprintf('You need about %d liters of paint.', ceil($area * $coats / self::PAINT_COVERAGE_PER_LITER));
    }

    /** Tile sizes are in centimeters. */
    private function tiles(float $area, float $length, float $width): ?string
    {
        if ($area <= 0 || $length <= 0 || $width <= 0) {
            return null;
        }

        $tiles = ceil($area / ($length * $width / 10000) * self::TILE_WASTE_FACTOR);

        return sprintf('You need about %d tiles, including 10%% extra for cuts.', $tiles);
    }

    private function lumber(float $totalLength, float $pieceLength): ?string
    {
        if ($totalLength <= 0 || $pieceLength <= 0) {
            return null;
        }

        return sprintf('You need %d pieces of lumber.', ceil($totalLength / $pieceLength));
    }
}
