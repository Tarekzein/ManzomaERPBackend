<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Models\TrustedLoginDevice;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrustedDeviceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()
            ->trustedLoginDevices()
            ->latest('last_used_at')
            ->get(['id', 'device_name', 'ip_address', 'last_used_at', 'expires_at', 'created_at']);

        return ApiResponse::success($devices, 'Trusted devices loaded');
    }

    public function destroy(Request $request, TrustedLoginDevice $device): JsonResponse
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        $device->delete();

        return ApiResponse::success(null, 'Trusted device removed');
    }

    public function destroyAll(Request $request): JsonResponse
    {
        $request->user()->trustedLoginDevices()->delete();

        return ApiResponse::success(null, 'Trusted devices removed');
    }
}
