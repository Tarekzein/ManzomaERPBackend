<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Models\User;
use App\Modules\Subscriptions\DTOs\SubscribeData;
use App\Modules\Subscriptions\Http\Requests\SubscribeRequest;
use App\Modules\Subscriptions\Services\CompanySubscriptionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySubscriptionController extends Controller
{
    public function __construct(private readonly CompanySubscriptionService $subscriptions) {}

    public function current(Request $request): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->current($this->user($request)), 'Current subscription loaded');
    }

    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->subscriptions->subscribe($this->user($request), SubscribeData::from($request->validated())),
            'Company subscription activated',
            status: 201
        );
    }

    public function cancel(Request $request): JsonResponse
    {
        return ApiResponse::success($this->subscriptions->cancel($this->user($request)), 'Company subscription cancelled');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
