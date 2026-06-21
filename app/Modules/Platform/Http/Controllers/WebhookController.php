<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Models\WebhookDelivery;
use App\Modules\Platform\Models\WebhookEndpoint;
use App\Modules\Platform\Services\WebhookService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        return ApiResponse::success(
            WebhookEndpoint::where('company_id', $request->user()->company_id)->withCount('deliveries')->latest()->get(),
            'Webhook endpoints loaded'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'max:120'],
        ]);

        $endpoint = WebhookEndpoint::create($data + [
            'company_id' => $request->user()->company_id,
            'secret' => Str::random(48),
        ]);

        return ApiResponse::success($endpoint->makeVisible('secret'), 'Webhook endpoint created', status: 201);
    }

    public function update(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $this->authorizeEndpoint($request, $endpoint);
        $endpoint->update($request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'url' => ['sometimes', 'url', 'max:2048'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['required', 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return ApiResponse::success($endpoint->refresh(), 'Webhook endpoint updated');
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $this->authorizeEndpoint($request, $endpoint);
        $endpoint->delete();

        return ApiResponse::success(null, 'Webhook endpoint deleted');
    }

    public function deliveries(Request $request): JsonResponse
    {
        $this->authorizeAccess($request);

        return ApiResponse::success(
            WebhookDelivery::whereHas('endpoint', fn ($query) => $query->where('company_id', $request->user()->company_id))
                ->with('endpoint:id,name')
                ->latest()
                ->paginate(min(max($request->integer('per_page', 25), 1), 100)),
            'Webhook deliveries loaded'
        );
    }

    public function retry(Request $request, WebhookDelivery $delivery, WebhookService $webhooks): JsonResponse
    {
        $this->authorizeEndpoint($request, $delivery->endpoint);

        return ApiResponse::success(
            $webhooks->deliver($delivery->endpoint, $delivery->event, $delivery->payload['data'] ?? $delivery->payload),
            'Webhook delivery retried'
        );
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()->company_id && $request->user()->can('platform.edit'), 403);
    }

    private function authorizeEndpoint(Request $request, WebhookEndpoint $endpoint): void
    {
        $this->authorizeAccess($request);
        abort_unless($endpoint->company_id === $request->user()->company_id, 404);
    }
}
