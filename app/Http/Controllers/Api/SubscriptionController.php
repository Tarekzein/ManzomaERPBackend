<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionFeature;
use App\Models\SubscriptionPlan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function plans(): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->with('features')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return ApiResponse::success($plans, 'Subscription plans loaded');
    }

    public function features(): JsonResponse
    {
        $features = SubscriptionFeature::query()
            ->orderBy('module')
            ->orderBy('name')
            ->get()
            ->groupBy('module');

        return ApiResponse::success($features, 'Subscription features loaded');
    }

    public function current(Request $request): JsonResponse
    {
        $subscription = $request->user()
            ->company
            ?->subscription()
            ->with('plan.features')
            ->first();

        return ApiResponse::success($subscription, 'Current subscription loaded');
    }
}
