<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Models\User;
use App\Modules\MetaIntegration\Models\MetaPage;
use App\Modules\Platform\Models\SocialInteraction;
use App\Modules\Platform\Services\SocialInboxService;
use App\Modules\Platform\Services\SocialPublishingService;
use App\Support\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The social inbox and publishing, both scoped to the caller's company.
 */
class SocialInboxController extends Controller
{
    public function __construct(private readonly SocialInboxService $inbox) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request, 'crm.view');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['new', 'handled', 'ignored'])],
            'platform' => ['nullable', Rule::in(['facebook', 'instagram', 'whatsapp', 'tiktok'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $interactions = SocialInteraction::query()
            ->with('contact:id,name,email', 'task:id,title,status', 'handler:id,name')
            ->where('company_id', $companyId)
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->when(isset($filters['platform']), fn ($query) => $query->where('platform', $filters['platform']))
            ->latest('posted_at')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return ApiResponse::success($interactions, 'Social inbox loaded');
    }

    public function summary(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request, 'crm.view');

        return ApiResponse::success([
            'open' => SocialInteraction::where('company_id', $companyId)->open()->count(),
            'by_platform' => SocialInteraction::where('company_id', $companyId)
                ->open()
                ->selectRaw('platform, count(*) as total')
                ->groupBy('platform')
                ->pluck('total', 'platform'),
        ], 'Social inbox summary loaded');
    }

    /** Backfill comments for a connected page. */
    public function importComments(Request $request, MetaPage $page): JsonResponse
    {
        $companyId = $this->companyId($request, 'crm.edit');
        abort_unless((int) $page->company_id === $companyId, 403, 'This page belongs to another company.');

        return ApiResponse::success(
            ['imported' => $this->inbox->importPageComments($page)],
            'Comments imported'
        );
    }

    public function convertToTask(Request $request, SocialInteraction $interaction): JsonResponse
    {
        $user = $this->authorize($request, $interaction, 'crm.edit');
        $data = $request->validate([
            'assignee_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn (Builder $query) => $query->where('company_id', $interaction->company_id)
                ),
            ],
            'title' => ['nullable', 'string', 'max:150'],
        ]);

        $alreadyConverted = $interaction->crm_task_id !== null;
        $task = $this->inbox->convertToTask(
            $interaction,
            $user,
            $data['assignee_id'] ?? null,
            $data['title'] ?? null
        );

        return ApiResponse::success(
            $task,
            $alreadyConverted ? 'This interaction already has a task' : 'Task created from the social interaction',
            status: $alreadyConverted ? 200 : 201
        );
    }

    public function updateStatus(Request $request, SocialInteraction $interaction): JsonResponse
    {
        $user = $this->authorize($request, $interaction, 'crm.edit');
        $data = $request->validate(['status' => ['required', Rule::in(['new', 'handled', 'ignored'])]]);

        return ApiResponse::success(
            $this->inbox->markHandled($interaction, $user, $data['status']),
            'Interaction updated'
        );
    }

    public function reply(Request $request, SocialInteraction $interaction): JsonResponse
    {
        $user = $this->authorize($request, $interaction, 'crm.edit');
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        $result = $this->inbox->reply($interaction, $data['message']);
        $this->inbox->markHandled($interaction, $user);

        return ApiResponse::success($result, 'Reply posted');
    }

    public function publish(Request $request, SocialPublishingService $publishing): JsonResponse
    {
        $companyId = $this->companyId($request, 'crm.edit');
        $data = $request->validate([
            'page_id' => ['required', 'integer', 'exists:meta_pages,id'],
            'platform' => ['required', Rule::in(['facebook', 'instagram'])],
            'message' => ['required_if:platform,facebook', 'nullable', 'string', 'max:5000'],
            'link' => ['nullable', 'url', 'max:500'],
            'image_url' => ['required_if:platform,instagram', 'nullable', 'url', 'max:500'],
            'caption' => ['nullable', 'string', 'max:2200'],
        ]);

        $page = MetaPage::findOrFail($data['page_id']);
        abort_unless((int) $page->company_id === $companyId, 403, 'This page belongs to another company.');

        return ApiResponse::success(
            $data['platform'] === 'instagram'
                ? $publishing->publishToInstagram($page, $data['image_url'], $data['caption'] ?? null)
                : $publishing->publishToPage($page, $data['message'], $data['link'] ?? null),
            'Post published',
            status: 201
        );
    }

    private function authorize(Request $request, SocialInteraction $interaction, string $permission): User
    {
        $companyId = $this->companyId($request, $permission);
        abort_unless((int) $interaction->company_id === $companyId, 403, 'This interaction belongs to another company.');

        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function companyId(Request $request, string $permission): int
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->can($permission), 403, 'You are not allowed to manage the social inbox.');
        abort_unless($user->company_id !== null, 403, 'A company assignment is required.');

        return (int) $user->company_id;
    }
}
