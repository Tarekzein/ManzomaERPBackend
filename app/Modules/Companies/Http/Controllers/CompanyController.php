<?php

namespace App\Modules\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Companies\Services\CompanyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function __construct(private readonly CompanyService $companies) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->companies->list(
                $request->user(),
                $request->string('search')->trim()->toString(),
                $request->integer('per_page', 15),
            ),
            'Companies loaded'
        );
    }
}
