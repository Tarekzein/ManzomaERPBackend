<?php

namespace App\Modules\MetaIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaIntegration\Http\Requests\MetaRequest;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Policies\MetaIntegrationPolicy;
use App\Modules\MetaIntegration\Services\MetaAssetService;
use App\Modules\MetaIntegration\Services\MetaConversionService;
use App\Modules\MetaIntegration\Services\MetaGraphClient;
use App\Modules\MetaIntegration\Services\MetaOAuthService;
use App\Modules\MetaIntegration\Services\MetaSetupService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MetaConnectionController extends Controller
{
    public function __construct(
        private readonly MetaOAuthService $oauth,
        private readonly MetaAssetService $assets,
        private readonly MetaIntegrationPolicy $policy,
    ) {}

    public function show(Request $request)
    {
        $companyId = $this->policy->companyId($request->user());
        $connection = MetaConnection::where('company_id', $companyId)->first();

        return ApiResponse::success($connection);
    }

    public function authorizationUrl(Request $request)
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success($this->oauth->authorizationUrl($companyId, $request->user()));
    }

    public function callback(MetaRequest $request)
    {
        $connection = $this->oauth->handleCallback($request->string('code'), $request->string('state'));

        return ApiResponse::success($connection, 'Meta account connected');
    }

    public function storeManual(MetaRequest $request)
    {
        $companyId = $this->policy->companyId($request->user(), 'meta.create');
        $connection = $this->oauth->storeManualCredentials($companyId, $request->user(), $request->validated());

        return ApiResponse::success($connection, 'Meta credentials saved', status: 201);
    }

    public function storeAppCredentials(MetaRequest $request)
    {
        $companyId = $this->policy->companyId($request->user(), 'meta.create');
        $connection = $this->oauth->saveAppCredentials(
            $companyId,
            $request->user(),
            $request->string('app_id'),
            $request->string('app_secret'),
            $request->string('config_id') ?: null,
        );

        return ApiResponse::success($connection, 'Meta App credentials saved');
    }

    public function updateCompliance(MetaRequest $request)
    {
        $companyId = $this->policy->companyId($request->user(), 'meta.edit');
        $connection = MetaConnection::where('company_id', $companyId)->firstOrFail();
        $connection->update($request->validated());

        return ApiResponse::success($connection->fresh(), 'Meta compliance settings updated');
    }

    public function destroy(Request $request)
    {
        $companyId = $this->policy->companyId($request->user(), 'meta.delete');
        // Default keeps the connection row (and its event history) so reports
        // stay intact; purge=1 is the "erase everything" path.
        $purge = $request->boolean('purge');
        $this->oauth->disconnect($companyId, $purge);

        return ApiResponse::success(null, $purge
            ? 'Meta account disconnected and history erased'
            : 'Meta account disconnected');
    }

    public function businesses(Request $request)
    {
        return ApiResponse::success($this->assets->businesses($this->connection($request)));
    }

    public function adAccounts(Request $request)
    {
        return ApiResponse::success($this->assets->adAccounts($this->connection($request), $request->string('business_id')));
    }

    public function pixels(Request $request)
    {
        return ApiResponse::success($this->assets->pixels($this->connection($request), $request->string('ad_account_id')));
    }

    public function pages(Request $request)
    {
        return ApiResponse::success($this->assets->pages($this->connection($request)));
    }

    public function leadForms(Request $request)
    {
        return ApiResponse::success($this->assets->leadForms($this->connection($request), $request->string('page_id')));
    }

    public function saveAssets(MetaRequest $request)
    {
        $connection = $this->assets->selectAssets($this->connection($request), $request->validated());

        return ApiResponse::success($connection, 'Meta assets updated');
    }

    public function sendTestEvent(MetaRequest $request, MetaConversionService $conversions)
    {
        $connection = $this->connection($request);
        $testCode = $request->string('test_event_code') ?: $connection->test_event_code;

        $response = (new MetaGraphClient($connection))->post("{$connection->pixel_id}/events", [
            'data' => json_encode([[
                'event_name' => 'TestEvent',
                'event_time' => now()->timestamp,
                'event_id' => (string) Str::uuid(),
                'action_source' => 'system_generated',
                'user_data' => ['client_ip_address' => $request->ip(), 'client_user_agent' => $request->userAgent()],
            ]]),
            'test_event_code' => $testCode,
        ]);

        return ApiResponse::success($response, 'Test event sent to Meta');
    }

    /**
     * Everything the company needs to configure their own Meta App, plus a
     * checklist of what is still outstanding.
     */
    public function setup(Request $request, MetaSetupService $setup)
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success($setup->instructions($companyId), 'Meta setup details loaded');
    }

    public function rotateVerifyToken(Request $request, MetaSetupService $setup)
    {
        $companyId = $this->policy->companyId($request->user(), 'meta.edit');
        $connection = MetaConnection::where('company_id', $companyId)->firstOrFail();

        return ApiResponse::success(
            ['verify_token' => $setup->rotateVerifyToken($connection)],
            'A new verify token was issued. Update it in your Meta App webhook settings.'
        );
    }

    public function health(Request $request)
    {
        $connection = $this->connection($request);

        try {
            $me = (new MetaGraphClient($connection))->get('me', ['fields' => 'id,name']);
            $connection->update(['last_health_check_at' => now(), 'last_error' => null]);

            return ApiResponse::success(['status' => $connection->status, 'graph' => $me, 'last_health_check_at' => $connection->last_health_check_at]);
        } catch (\Throwable $e) {
            $connection->update(['status' => 'error', 'last_error' => $e->getMessage()]);

            return ApiResponse::success(['status' => 'error', 'error' => $e->getMessage()]);
        }
    }

    private function connection(Request $request): MetaConnection
    {
        $companyId = $this->policy->companyId($request->user());

        return MetaConnection::where('company_id', $companyId)->firstOrFail();
    }
}
