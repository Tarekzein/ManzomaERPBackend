<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Subscriptions\Services\SubscriptionCatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SubscriptionCatalogController extends Controller
{
    public function __construct(private readonly SubscriptionCatalogService $catalog) {}

    public function plans(): JsonResponse
    {
        return ApiResponse::success($this->catalog->plans(), 'Subscription plans loaded');
    }

    public function features(): JsonResponse
    {
        return ApiResponse::success($this->catalog->features(), 'Subscription features loaded');
    }
}
