<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Services\AuthenticationService;
use App\Modules\Subscriptions\Services\SubscriptionPaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

    /** Re-open a checkout session whose URL expired or failed to be created. */
    public function retryCheckout(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'registration_token' => ['required', 'string'],
        ]);

        $payment = $this->payments->findForRegistration($reference, $data['registration_token']);

        abort_if($payment->isSuccessful(), 409, 'This payment has already been completed.');

        // Returns the live session when there is one; only a missing or expired
        // session mints a new Paymob order.
        $payment = $this->payments->openCheckout($payment);

        abort_if($payment->checkout_url === null, 502, 'The payment gateway could not start a checkout session. Please try again.');

        return ApiResponse::success($payment, 'Checkout session refreshed');
    }

    /**
     * Exchange a completed registration checkout for an API token, so the
     * customer lands signed in after paying on Paymob's hosted page.
     */
    public function session(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate([
            'registration_token' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:120'],
        ]);

        $payment = $this->payments->findForRegistration($reference, $data['registration_token']);

        abort_unless($payment->isSuccessful(), 409, 'This payment has not been completed yet.');
        abort_unless($payment->user !== null, 404, 'The account for this checkout is no longer available.');

        return ApiResponse::success([
            'payment' => $payment,
            'auth' => $this->auth->tokenResponse($payment->user, $data['device_name'] ?? 'ManzomaERP Web'),
        ], 'Checkout session completed');
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

    /**
     * Paymob server-to-server callback. Always answers 200 once the signature
     * checks out so Paymob does not keep retrying a payload we already stored.
     */
    public function callback(Request $request): JsonResponse
    {
        $result = $this->payments->handleCallback($request->all(), $this->signature($request));

        return ApiResponse::success([
            'payment' => $result['payment'],
            'handled' => $result['handled'] ?? true,
        ], 'Paymob callback processed');
    }

    /**
     * Paymob response callback: the customer's browser lands here after paying
     * and is sent back to the app with the outcome.
     */
    public function redirectResult(Request $request): RedirectResponse
    {
        $payload = $request->all();
        $reference = $this->scalar($request->query('merchant_order_id')) ?: $this->scalar($request->query('order'));
        $status = 'pending';

        try {
            $result = $this->payments->handleCallback($payload, $this->signature($request));
            $payment = $result['payment'] ?? null;
            $reference = $payment?->reference ?: $reference;
            $status = $payment?->status ?: $status;
        } catch (\Throwable $exception) {
            // A bad signature must not strand the customer on a blank page.
            Log::warning('[paymob] redirect callback could not be processed', [
                'message' => $exception->getMessage(),
            ]);
            $status = 'unknown';
        }

        return redirect()->away($this->frontendUrl($reference, $status));
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function signature(Request $request): ?string
    {
        return $request->query('hmac')
            ?: $request->header('X-Paymob-Signature')
            ?: $request->input('hmac');
    }

    private function frontendUrl(string $reference, string $status): string
    {
        $base = rtrim((string) config('subscriptions.checkout.app_url'), '/');
        $path = str_replace('{reference}', $reference, (string) config('subscriptions.checkout.return_path'));

        return $base.'/'.ltrim($path, '/').'?'.http_build_query(['status' => $status]);
    }
}
