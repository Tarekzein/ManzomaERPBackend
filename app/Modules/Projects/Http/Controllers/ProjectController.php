<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\Requests\StoreAttachmentRequest;
use App\Modules\Projects\Http\Requests\StoreCommentRequest;
use App\Modules\Projects\Http\Requests\StoreProjectExpenseRequest;
use App\Modules\Projects\Http\Requests\StoreProjectRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectRequest;
use App\Modules\Projects\Http\Resources\ProjectAttachmentResource;
use App\Modules\Projects\Http\Resources\ProjectCommentResource;
use App\Modules\Projects\Http\Resources\ProjectExpenseResource;
use App\Modules\Projects\Http\Resources\ProjectResource;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Services\ProjectReportingService;
use App\Modules\Projects\Services\ProjectService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectReportingService $reports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            ProjectResource::collection($this->projects->list(
                $request->user(),
                $request->integer('per_page', 15),
                $this->filters($request),
                $this->sort($request)
            )),
            'Projects loaded'
        );
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        return ApiResponse::success(
            ProjectResource::make($this->projects->create($request->user(), $request->validated())),
            'Project created',
            status: 201
        );
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        return ApiResponse::success(
            ProjectResource::make($this->projects->show($request->user(), $project)),
            'Project loaded'
        );
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        return ApiResponse::success(
            ProjectResource::make($this->projects->update($request->user(), $project, $request->validated())),
            'Project updated'
        );
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        $this->projects->delete($request->user(), $project);

        return ApiResponse::success(null, 'Project deleted');
    }

    public function gantt(Request $request): JsonResponse
    {
        return ApiResponse::success($this->reports->gantt($request->user()), 'Project timeline loaded');
    }

    public function report(Request $request, Project $project): JsonResponse
    {
        return ApiResponse::success($this->reports->summary($request->user(), $project), 'Project report loaded');
    }

    public function expense(StoreProjectExpenseRequest $request, Project $project): JsonResponse
    {
        return ApiResponse::success(
            ProjectExpenseResource::make($this->projects->recordExpense($request->user(), $project, $request)),
            'Project expense recorded',
            status: 201
        );
    }

    public function attach(StoreAttachmentRequest $request, Project $project): JsonResponse
    {
        return ApiResponse::success(
            ProjectAttachmentResource::make($this->projects->attachFile(
                $request->user(),
                $project,
                $request->file('file'),
                $request->validated('comment')
            )),
            'Project file attached',
            status: 201
        );
    }

    public function comment(StoreCommentRequest $request, Project $project): JsonResponse
    {
        return ApiResponse::success(
            ProjectCommentResource::make($this->projects->comment($request->user(), $project, $request->validated('body'))),
            'Project comment added',
            status: 201
        );
    }

    private function filters(Request $request): array
    {
        $filters = $request->query('filter', []);

        return is_array($filters) ? $filters : [];
    }

    private function sort(Request $request): ?string
    {
        $sort = $request->query('sort');

        return is_string($sort) ? $sort : null;
    }
}
