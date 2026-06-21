<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Services\UsageService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackApiUsage
{
    public function __construct(private readonly UsageService $usage) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $user = $request->user();

        if ($user?->company_id) {
            $this->usage->increment($user->company_id, 'api_calls');
            $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        return $response;
    }
}
