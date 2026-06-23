<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Services\AuthenticationService;
use App\Modules\Subscriptions\Services\SubscriptionPaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionPaymentController extends Controller
{
    public function __construct(
        private readonly SubscriptionPaymentService $payments,
        private readonly AuthenticationService $auth,
    ) {}

    public function status(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'registration_token' => ['required', 'string'],
        ]);

        return ApiResponse::success(
            $this->payments->findForRegistration($reference, $data['registration_token']),
            'Payment status loaded'
        );
    }

    public function mockResult(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'registration_token' => ['required', 'string'],
            'status' => ['required', Rule::in(['succeeded', 'failed', 'pending'])],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]);

        $result = $this->payments->resolveMock($reference, $data['registration_token'], $data['status']);
        $payment = $result['payment']->load('user');
        $auth = $payment->status === 'succeeded'
            ? $this->auth->tokenResponse($payment->user, $data['device_name'] ?? 'ManzomaERP Web')
            : null;

        return ApiResponse::success([
            'payment' => $payment,
            'auth' => $auth,
        ], 'Mock payment resolved');
    }

    public function callback(Request $request): JsonResponse
    {
        $result = $this->payments->handleCallback(
            $request->all(),
            $request->header('X-Paymob-Signature') ?: $request->query('hmac')
        );

        return ApiResponse::success(['payment' => $result['payment']], 'Paymob callback processed');
    }
}
