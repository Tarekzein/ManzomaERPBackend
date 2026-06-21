<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

class TwoFactorController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'enabled' => $request->user()->hasEnabledTwoFactorAuthentication(),
            'pending_confirmation' => $request->user()->two_factor_secret !== null && $request->user()->two_factor_confirmed_at === null,
        ], 'Two-factor status loaded');
    }

    public function enable(Request $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        $this->validatePassword($request);
        $enable($request->user(), true);

        return ApiResponse::success([
            'qr_code_svg' => $request->user()->twoFactorQrCodeSvg(),
            'recovery_codes' => $request->user()->recoveryCodes(),
        ], 'Two-factor authentication enrollment started');
    }

    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $confirm($request->user(), $data['code']);

        return ApiResponse::success(['enabled' => true], 'Two-factor authentication enabled');
    }

    public function disable(Request $request, DisableTwoFactorAuthentication $disable): JsonResponse
    {
        $this->validatePassword($request);
        $disable($request->user());

        return ApiResponse::success(['enabled' => false], 'Two-factor authentication disabled');
    }

    public function recoveryCodes(Request $request, GenerateNewRecoveryCodes $generate): JsonResponse
    {
        $this->validatePassword($request);
        abort_unless($request->user()->hasEnabledTwoFactorAuthentication(), 422, 'Two-factor authentication is not enabled.');
        $generate($request->user());

        return ApiResponse::success(['recovery_codes' => $request->user()->recoveryCodes()], 'Recovery codes regenerated');
    }

    private function validatePassword(Request $request): void
    {
        $request->validate(['password' => ['required', 'current_password']]);
    }
}
