<?php

namespace App\Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CRM\Http\Requests\CRMRequest;
use App\Modules\CRM\Models\CRMActivity;
use App\Modules\CRM\Models\CRMCampaign;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMOpportunity;
use App\Modules\CRM\Models\CRMPipelineStage;
use App\Modules\CRM\Models\CRMSegment;
use App\Modules\CRM\Models\CRMTag;
use App\Modules\CRM\Models\CRMTask;
use App\Modules\CRM\Services\CRMService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CRMController extends Controller
{
    public function __construct(private readonly CRMService $crm) {}

    public function contacts(Request $request)
    {
        return ApiResponse::success($this->crm->list($request->user(), CRMContact::class, $request, ['owner', 'salesContact', 'tags']));
    }

    public function storeContact(CRMRequest $request)
    {
        return ApiResponse::success($this->crm->saveContact($request->user(), $request->validated()), 'CRM contact created', status: 201);
    }

    public function updateContact(CRMRequest $request, CRMContact $contact)
    {
        return ApiResponse::success($this->crm->saveContact($request->user(), $request->validated(), $contact), 'CRM contact updated');
    }

    public function deleteContact(Request $request, CRMContact $contact)
    {
        $this->crm->delete($request->user(), $contact);

        return ApiResponse::success(null, 'CRM contact deleted');
    }

    public function convertContact(CRMRequest $request, CRMContact $contact)
    {
        return ApiResponse::success($this->crm->convertContact($request->user(), $contact, $request->validated()), 'CRM contact converted to sales contact');
    }

    public function tags(Request $request)
    {
        return ApiResponse::success($this->crm->list($request->user(), CRMTag::class, $request));
    }

    public function storeTag(CRMRequest $request)
    {
        return ApiResponse::success($this->crm->saveTag($request->user(), $request->validated()), 'CRM tag created', status: 201);
    }

    public function updateTag(CRMRequest $request, CRMTag $tag)
    {
        return ApiResponse::success($this->crm->saveTag($request->user(), $request->validated(), $tag), 'CRM tag updated');
    }

    public function deleteTag(Request $request, CRMTag $tag)
    {
        $this->crm->delete($request->user(), $tag);

        return ApiResponse::success(null, 'CRM tag deleted');
    }

    public function stages(Request $request)
    {
        $stages = $this->crm->list($request->user(), CRMPipelineStage::class, $request, ['opportunities.contact'])
            ->sortBy('sort_order')
            ->values();

        return ApiResponse::success($stages);
    }

    public function storeStage(CRMRequest $request)
    {
        return ApiResponse::success($this->crm->saveStage($request->user(), $request->validated()), 'Pipeline stage created', status: 201);
    }

    public function updateStage(CRMRequest $request, CRMPipelineStage $stage)
    {
        return ApiResponse::success($this->crm->saveStage($request->user(), $request->validated(), $stage), 'Pipeline stage updated');
    }

    public function deleteStage(Request $request, CRMPipelineStage $stage)
    {
        $this->crm->delete($request->user(), $stage);

        return ApiResponse::success(null, 'Pipeline stage deleted');
    }

    public function reorderStages(CRMRequest $request)
    {
        return ApiResponse::success($this->crm->reorderStages($request->user(), $request->validated('stages')), 'Pipeline stages reordered');
    }

    public function opportunities(Request $request)
    {
        return ApiResponse::success($this->crm->list($request->user(), CRMOpportunity::class, $request, ['contact.tags', 'stage', 'owner']));
    }

    public function storeOpportunity(CRMRequest $request)
    {
        return ApiResponse::success($this->crm->saveOpportunity($request->user(), $request->validated()), 'Opportunity created', status: 201);
    }

    public function updateOpportunity(CRMRequest $request, CRMOpportunity $opportunity)
    {
        return ApiResponse::success($this->crm->saveOpportunity($request->user(), $request->validated(), $opportunity), 'Opportunity updated');
    }

    public function deleteOpportunity(Request $request, CRMOpportunity $opportunity)
    {
        $this->crm->delete($request->user(), $opportunity);

        return ApiResponse::success(null, 'Opportunity deleted');
    }

    public function moveOpportunity(CRMRequest $request, CRMOpportunity $opportunity)
    {
        return ApiResponse::success($this->crm->moveOpportunity($request->user(), $opportunity, (int) $request->validated('stage_id')), 'Opportunity moved');
    }

    public function activities(Request $request)
    {
        return ApiResponse::success($this->crm->list($request->user(), CRMActivity::class, $request, ['contact', 'opportunity', 'user']));
    }

    public function storeActivity(CRMRequest $request)
    {
        return ApiResponse::success($this->crm->saveActivity($request->user(), $request->validated()), 'CRM activity logged', status: 201);
    }

    public function tasks(Request $request)
    {
        return ApiResponse::success($this->crm->list($request->user(), CRMTask::class, $request, ['contact', 'opportunity', 'assignee']));
    }

    public function storeTask(CRMRequest $request)
    {
        return ApiResponse::success($this->crm->saveTask($request->user(), $request->validated()), 'CRM task created', status: 201);
    }

    public function updateTask(CRMRequest $request, CRMTask $task)
    {
        return ApiResponse::success($this->crm->saveTask($request->user(), $request->validated(), $task), 'CRM task updated');
    }

    public function deleteTask(Request $request, CRMTask $task)
    {
        $this->crm->delete($request->user(), $task);

        return ApiResponse::success(null, 'CRM task deleted');
    }

    public function completeTask(Request $request, CRMTask $task)
    {
        return ApiResponse::success($this->crm->completeTask($request->user(), $task), 'CRM task completed');
    }

    public function segments(Request $request)
    {
        return ApiResponse::success($this->crm->list($request->user(), CRMSegment::class, $request));
    }

    public function storeSegment(CRMRequest $request)
    {
        return ApiResponse::success($this->crm->saveSegment($request->user(), $request->validated()), 'CRM segment created', status: 201);
    }

    public function updateSegment(CRMRequest $request, CRMSegment $segment)
    {
        return ApiResponse::success($this->crm->saveSegment($request->user(), $request->validated(), $segment), 'CRM segment updated');
    }

    public function deleteSegment(Request $request, CRMSegment $segment)
    {
        $this->crm->delete($request->user(), $segment);

        return ApiResponse::success(null, 'CRM segment deleted');
    }

    public function segmentContacts(Request $request, CRMSegment $segment)
    {
        return ApiResponse::success($this->crm->segmentContacts($request->user(), $segment), 'Segment contacts loaded');
    }

    public function campaigns(Request $request)
    {
        return ApiResponse::success($this->crm->list($request->user(), CRMCampaign::class, $request, ['segment', 'events.contact']));
    }

    public function storeCampaign(CRMRequest $request)
    {
        return ApiResponse::success($this->crm->saveCampaign($request->user(), $request->validated()), 'CRM campaign created', status: 201);
    }

    public function updateCampaign(CRMRequest $request, CRMCampaign $campaign)
    {
        return ApiResponse::success($this->crm->saveCampaign($request->user(), $request->validated(), $campaign), 'CRM campaign updated');
    }

    public function deleteCampaign(Request $request, CRMCampaign $campaign)
    {
        $this->crm->delete($request->user(), $campaign);

        return ApiResponse::success(null, 'CRM campaign deleted');
    }

    public function campaignWebhook(CRMRequest $request, string $provider)
    {
        return ApiResponse::success($this->crm->recordCampaignEvent($request->user(), $provider, $request->validated()), 'Campaign event recorded', status: 201);
    }

    public function report(Request $request, string $report)
    {
        return ApiResponse::success($this->crm->reports($request->user(), $request, $report), 'CRM report loaded');
    }
}
