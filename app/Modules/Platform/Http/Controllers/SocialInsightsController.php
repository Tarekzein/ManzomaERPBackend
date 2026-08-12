<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Models\User;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Platform\Services\SocialInsightsService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Social performance across Meta and TikTok, and the drill-down from a campaign
 * to the leads it produced.
 */
class SocialInsightsController extends Controller
{
    public function __construct(
        private readonly SocialInsightsService $insights,
        private readonly CompanyContext $context,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $days = (int) $request->integer('days', 30);

        return ApiResponse::success(
            $this->insights->summary($this->companyId($user), in_array($days, [7, 30, 90], true) ? $days : 30),
            'Social insights loaded'
        );
    }

    public function campaignLeads(Request $request, string $platform, string $campaignId): JsonResponse
    {
        $request->merge(['platform' => $platform])->validate([
            'platform' => ['required', Rule::in(['meta', 'tiktok'])],
        ]);

        return ApiResponse::success(
            $this->insights->campaignLeads($this->companyId($this->user($request)), $platform, $campaignId),
            'Campaign leads loaded'
        );
    }

    private function companyId(User $user): int
    {
        abort_unless($user->can('crm.view'), 403, 'You are not allowed to view social insights.');
        $companyId = $this->context->companyIdFor($user);
        abort_unless($companyId !== null, 403, 'A company assignment is required.');

        return $companyId;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
