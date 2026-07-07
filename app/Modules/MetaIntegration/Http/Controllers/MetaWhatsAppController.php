<?php

namespace App\Modules\MetaIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaIntegration\Http\Requests\MetaRequest;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Policies\MetaIntegrationPolicy;
use App\Modules\MetaIntegration\Services\MetaWhatsAppService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class MetaWhatsAppController extends Controller
{
    public function __construct(
        private readonly MetaWhatsAppService $whatsapp,
        private readonly MetaIntegrationPolicy $policy,
    ) {}

    public function businessAccounts(Request $request)
    {
        return ApiResponse::success($this->whatsapp->businessAccounts($this->connection($request)));
    }

    public function phoneNumbers(Request $request)
    {
        return ApiResponse::success($this->whatsapp->phoneNumbers($this->connection($request), $request->string('waba_id')));
    }

    public function saveSettings(MetaRequest $request)
    {
        $connection = $this->connection($request, 'meta.edit');
        $updated = $this->whatsapp->saveSettings($connection, $request->validated());

        return ApiResponse::success($updated, 'WhatsApp settings saved');
    }

    public function sendTemplate(MetaRequest $request)
    {
        $connection = $this->connection($request, 'meta.edit');
        $response = $this->whatsapp->sendTemplate($connection, $request->validated() + ['user_id' => $request->user()->id]);

        return ApiResponse::success($response, 'WhatsApp template message sent');
    }

    private function connection(Request $request, string $permission = 'meta.view'): MetaConnection
    {
        $companyId = $this->policy->companyId($request->user(), $permission);

        return MetaConnection::where('company_id', $companyId)->firstOrFail();
    }
}
