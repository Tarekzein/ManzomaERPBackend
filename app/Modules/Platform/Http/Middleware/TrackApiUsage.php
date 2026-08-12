<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Platform\Services\UsageService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackApiUsage
{
    public function __construct(
        private readonly UsageService $usage,
        private readonly CompanyContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();

        if ($user && ($companyId = $this->context->companyId())) {
            $this->usage->increment($companyId, 'api_calls');
            $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        return $response;
    }
}
