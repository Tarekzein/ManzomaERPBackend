<?php

namespace App\Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Services\PosTerminalService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosTerminalController extends Controller
{
    public function __construct(
        private readonly PosTerminalService $terminals,
    ) {}

    /** Open an attempt before the cashier touches the terminal. */
    public function intent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pos_register_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'provider' => ['sometimes', 'nullable', 'string', 'max:40'],
            'external_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::success(
            $this->terminals->intent($request->user(), $data),
            'Terminal payment started',
            status: 201,
        );
    }

    /**
     * Operator-confirmed capture for a manually operated terminal.
     *
     * An assigned supervisor reads the approval off the device. It is recorded
     * as verified-by-operator rather than verified-by-provider, and the acting
     * user is on the record as the manual terminal's audit trail.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_reference' => ['required', 'string', 'max:255'],
            // This browser endpoint exists only for an attended manual device.
            // Integrated acquirers must confirm through their signed server
            // callback or a server-side status poll.
            'provider' => ['sometimes', 'string', Rule::in(['manual_terminal'])],
            'approved' => ['required', 'boolean'],
            'auth_code' => ['sometimes', 'nullable', 'string', 'max:40'],
            'card_brand' => ['sometimes', 'nullable', 'string', 'max:24'],
            'last4' => ['sometimes', 'nullable', 'string', 'max:4'],
            'failure_reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::success(
            $this->terminals->confirmManual(
                $request->user(),
                $data['external_reference'],
                (bool) $data['approved'],
                [
                    'auth_code' => $data['auth_code'] ?? null,
                    'card_brand' => $data['card_brand'] ?? null,
                    'last4' => $data['last4'] ?? null,
                    'operator_user_id' => $request->user()->getKey(),
                ],
                $data['failure_reason'] ?? null,
            ),
            'Terminal payment confirmed',
        );
    }
}
