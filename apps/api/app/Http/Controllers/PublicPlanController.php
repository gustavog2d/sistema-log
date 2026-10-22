<?php

namespace App\Http\Controllers;

use App\Plans\PublicCatalog;
use Illuminate\Http\JsonResponse;

/** Planos ativos para a página de cadastro. A projeção pública fica em PublicCatalog. */
class PublicPlanController extends Controller
{
    public function __invoke(PublicCatalog $catalog): JsonResponse
    {
        return response()->json(['data' => $catalog->activePlans()])
            ->header('Cache-Control', 'public, max-age=60');
    }
}
