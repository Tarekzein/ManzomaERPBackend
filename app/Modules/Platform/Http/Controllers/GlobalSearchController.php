<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\GlobalSearchService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchService $search): JsonResponse
    {
        $query = $request->query('q');
        if (is_string($query)) {
            $request->merge(['q' => $search->normalizeTerm($query)]);
        }

        $data = $request->validate([
            'q' => ['bail', 'required', 'string', 'min:2', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:25'],
        ]);

        return ApiResponse::success(
            $search->search($request->user(), $data['q'], $data['limit'] ?? 8),
            'Search results loaded'
        );
    }
}
