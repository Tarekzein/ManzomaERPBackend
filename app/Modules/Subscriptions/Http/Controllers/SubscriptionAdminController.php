<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Models\User;
use App\Modules\Subscriptions\DTOs\FeatureData;
use App\Modules\Subscriptions\DTOs\PlanData;
use App\Modules\Subscriptions\Http\Requests\AssignFeaturesRequest;
use App\Modules\Subscriptions\Http\Requests\SaveFeatureRequest;
use App\Modules\Subscriptions\Http\Requests\SavePlanRequest;
use App\Modules\Subscriptions\Models\SubscriptionFeature;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Services\SubscriptionCatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionAdminController extends Controller
{
    public function __construct(private readonly SubscriptionCatalogService $catalog) {}

    public function storePlan(SavePlanRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->catalog->createPlan($this->user($request), PlanData::from($request->validated())),
            'Subscription plan created',
            status: 201
        );
    }

    public function updatePlan(SavePlanRequest $request, SubscriptionPlan $plan): JsonResponse
    {
        return ApiResponse::success(
            $this->catalog->updatePlan($this->user($request), $plan, PlanData::from($request->validated())),
            'Subscription plan updated'
        );
    }

    public function assignFeatures(AssignFeaturesRequest $request, SubscriptionPlan $plan): JsonResponse
    {
        return ApiResponse::success(
            $this->catalog->assignFeatures($this->user($request), $plan, $request->validated('features')),
            'Plan features assigned'
        );
    }

    public function storeFeature(SaveFeatureRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->catalog->createFeature($this->user($request), FeatureData::from($request->validated())),
            'Subscription feature created',
            status: 201
        );
    }

    public function updateFeature(SaveFeatureRequest $request, SubscriptionFeature $feature): JsonResponse
    {
        return ApiResponse::success(
            $this->catalog->updateFeature($this->user($request), $feature, FeatureData::from($request->validated())),
            'Subscription feature updated'
        );
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
