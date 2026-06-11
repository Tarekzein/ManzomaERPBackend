<?php

namespace App\Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Projects\Http\Requests\StoreAttachmentRequest;
use App\Modules\Projects\Http\Requests\StoreCommentRequest;
use App\Modules\Projects\Http\Requests\StoreProjectTaskRequest;
use App\Modules\Projects\Http\Requests\StoreTimeLogRequest;
use App\Modules\Projects\Http\Requests\UpdateProjectTaskRequest;
use App\Modules\Projects\Http\Resources\ProjectAttachmentResource;
use App\Modules\Projects\Http\Resources\ProjectCommentResource;
use App\Modules\Projects\Http\Resources\ProjectTaskResource;
use App\Modules\Projects\Http\Resources\ProjectTimeLogResource;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Projects\Services\ProjectTaskService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectTaskController extends Controller
{
    public function __construct(private readonly ProjectTaskService $tasks) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        return ApiResponse::success(
            ProjectTaskResource::collection($this->tasks->list(
                $request->user(),
                $project,
                $request->integer('per_page', 15),
                $this->filters($request),
                $this->sort($request)
            )),
            'Project tasks loaded'
        );
    }

    public function store(StoreProjectTaskRequest $request, Project $project): JsonResponse
    {
        return ApiResponse::success(
            ProjectTaskResource::make($this->tasks->create($request->user(), $project, $request->validated())),
            'Project task created',
            status: 201
        );
    }

    public function show(Request $request, ProjectTask $task): JsonResponse
    {
        return ApiResponse::success(
            ProjectTaskResource::make($this->tasks->show($request->user(), $task)),
            'Project task loaded'
        );
    }

    public function update(UpdateProjectTaskRequest $request, ProjectTask $task): JsonResponse
    {
        return ApiResponse::success(
            ProjectTaskResource::make($this->tasks->update($request->user(), $task, $request->validated())),
            'Project task updated'
        );
    }

    public function destroy(Request $request, ProjectTask $task): JsonResponse
    {
        $this->tasks->delete($request->user(), $task);

        return ApiResponse::success(null, 'Project task deleted');
    }

    public function logTime(StoreTimeLogRequest $request, ProjectTask $task): JsonResponse
    {
        return ApiResponse::success(
            ProjectTimeLogResource::make($this->tasks->logTime($request->user(), $task, $request->validated())),
            'Task time logged',
            status: 201
        );
    }

    public function attach(StoreAttachmentRequest $request, ProjectTask $task): JsonResponse
    {
        return ApiResponse::success(
            ProjectAttachmentResource::make($this->tasks->attachFile(
                $request->user(),
                $task,
                $request->file('file'),
                $request->validated('comment')
            )),
            'Task file attached',
            status: 201
        );
    }

    public function comment(StoreCommentRequest $request, ProjectTask $task): JsonResponse
    {
        return ApiResponse::success(
            ProjectCommentResource::make($this->tasks->comment($request->user(), $task, $request->validated('body'))),
            'Task comment added',
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
