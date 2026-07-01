<?php

namespace App\Modules\CRM\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\CRM\Models\CRMActivity;
use App\Modules\CRM\Models\CRMCampaign;
use App\Modules\CRM\Models\CRMCampaignEvent;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMNote;
use App\Modules\CRM\Models\CRMOpportunity;
use App\Modules\CRM\Models\CRMPipelineStage;
use App\Modules\CRM\Models\CRMSegment;
use App\Modules\CRM\Models\CRMTag;
use App\Modules\CRM\Models\CRMTask;
use App\Modules\CRM\Policies\CRMPolicy;
use App\Modules\Sales\Models\SalesContact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CRMService
{
    public function __construct(
        private readonly CRMPolicy $policy,
        private readonly CRMSetupService $setup,
    ) {}

    public function list(User $user, string $model, Request $request, array $with = [])
    {
        $companyId = $this->companyId($user, $request);
        $this->setup->ensureDefaultStages($companyId);

        $query = $model::query()->with($with)->where('company_id', $companyId);
        $this->applyCommonFilters($query, $request);

        if ($model === CRMContact::class) {
            $query->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
                ->when($request->filled('tag_id'), fn ($q) => $q->whereHas('tags', fn ($tagQuery) => $tagQuery->whereKey($request->integer('tag_id'))));
        }

        if ($model === CRMOpportunity::class) {
            $query->when($request->filled('stage_id'), fn ($q) => $q->where('stage_id', $request->integer('stage_id')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));
        }

        return $query->latest()->get();
    }

    public function saveContact(User $user, array $data, ?CRMContact $contact = null): CRMContact
    {
        $companyId = $contact ? $this->policy->ensureOwned($user, $contact) : $this->companyId($user, null, 'crm.create', $data['company_id'] ?? null);
        $this->ensureCompanyUser($companyId, $data['owner_id'] ?? null);
        $this->ensureSalesContact($companyId, $data['sales_contact_id'] ?? null);
        $tagIds = $this->validTagIds($companyId, $data['tag_ids'] ?? []);
        unset($data['company_id'], $data['tag_ids']);
        $data['status'] ??= 'new';
        $data['currency'] ??= 'EGP';

        return DB::transaction(function () use ($companyId, $data, $contact, $tagIds) {
            $record = $contact ?: new CRMContact(['company_id' => $companyId]);
            $record->fill($data)->save();
            $record->tags()->sync($tagIds);

            return $record->load('owner', 'salesContact', 'tags');
        });
    }

    public function convertContact(User $user, CRMContact $contact, array $data): CRMContact
    {
        $companyId = $this->policy->ensureOwned($user, $contact, 'crm.edit');
        $salesContactId = $data['sales_contact_id'] ?? null;
        $this->ensureSalesContact($companyId, $salesContactId);

        return DB::transaction(function () use ($contact, $salesContactId, $data) {
            $salesContact = $salesContactId
                ? SalesContact::findOrFail($salesContactId)
                : SalesContact::create([
                    'company_id' => $contact->company_id,
                    'type' => $data['type'] ?? 'customer',
                    'name' => $contact->company_name ?: $contact->name,
                    'email' => $contact->email,
                    'phone' => $contact->phone,
                    'currency' => $data['currency'] ?? $contact->currency,
                    'address' => $contact->address,
                ]);

            $contact->update([
                'type' => 'customer',
                'status' => 'converted',
                'sales_contact_id' => $salesContact->id,
                'converted_at' => now(),
            ]);

            return $contact->refresh()->load('salesContact', 'tags', 'owner');
        });
    }

    public function saveTag(User $user, array $data, ?CRMTag $tag = null): CRMTag
    {
        $companyId = $tag ? $this->policy->ensureOwned($user, $tag) : $this->companyId($user, null, 'crm.create', $data['company_id'] ?? null);
        unset($data['company_id']);

        return $tag ? tap($tag)->update($data) : CRMTag::create(['company_id' => $companyId] + $data);
    }

    public function saveStage(User $user, array $data, ?CRMPipelineStage $stage = null): CRMPipelineStage
    {
        $companyId = $stage ? $this->policy->ensureOwned($user, $stage) : $this->companyId($user, null, 'crm.create', $data['company_id'] ?? null);
        unset($data['company_id']);
        $data['key'] ??= Str::slug($data['name']);

        return $stage ? tap($stage)->update($data) : CRMPipelineStage::create(['company_id' => $companyId] + $data);
    }

    public function reorderStages(User $user, array $stages): array
    {
        $updated = [];
        foreach ($stages as $stageData) {
            $stage = CRMPipelineStage::findOrFail($stageData['id']);
            $this->policy->ensureOwned($user, $stage);
            $stage->update(['sort_order' => $stageData['sort_order']]);
            $updated[] = $stage->fresh();
        }

        return $updated;
    }

    public function saveOpportunity(User $user, array $data, ?CRMOpportunity $opportunity = null): CRMOpportunity
    {
        $companyId = $opportunity ? $this->policy->ensureOwned($user, $opportunity) : $this->companyId($user, null, 'crm.create', $data['company_id'] ?? null);
        $stage = $this->ensureStage($companyId, $data['stage_id']);
        $this->ensureContact($companyId, $data['contact_id']);
        $this->ensureCompanyUser($companyId, $data['owner_id'] ?? null);
        unset($data['company_id']);

        $data += [
            'currency' => 'EGP',
            'probability' => $stage->probability,
            'status' => $stage->is_won ? 'won' : ($stage->is_lost ? 'lost' : 'open'),
        ];
        $data = $this->applyStageStatus($data, $stage);

        $record = $opportunity ?: new CRMOpportunity(['company_id' => $companyId]);
        $record->fill($data)->save();

        return $record->load('contact.tags', 'stage', 'owner');
    }

    public function moveOpportunity(User $user, CRMOpportunity $opportunity, int $stageId): CRMOpportunity
    {
        $companyId = $this->policy->ensureOwned($user, $opportunity);
        $stage = $this->ensureStage($companyId, $stageId);
        $opportunity->update($this->applyStageStatus(['stage_id' => $stage->id, 'probability' => $stage->probability], $stage));

        return $opportunity->refresh()->load('contact.tags', 'stage', 'owner');
    }

    public function saveActivity(User $user, array $data): CRMActivity
    {
        $companyId = $this->resolveLinkedCompany($user, $data, 'crm.create');
        $this->assertCompanyAccess($user, $companyId, 'crm.create');
        $this->ensureContact($companyId, $data['contact_id'] ?? null);
        $this->ensureOpportunity($companyId, $data['opportunity_id'] ?? null);
        unset($data['company_id']);

        $activity = CRMActivity::create(['company_id' => $companyId, 'user_id' => $user->id, 'occurred_at' => $data['occurred_at'] ?? now()] + $data);
        if (! empty($data['contact_id'])) {
            $this->recomputeLeadScore(CRMContact::find($data['contact_id']));
        }

        return $activity->load('contact', 'opportunity', 'user');
    }

    public function saveTask(User $user, array $data, ?CRMTask $task = null): CRMTask
    {
        $companyId = $task ? $this->policy->ensureOwned($user, $task) : $this->resolveLinkedCompany($user, $data, 'crm.create');
        $this->assertCompanyAccess($user, $companyId, $task ? 'crm.edit' : 'crm.create');
        $this->ensureContact($companyId, $data['contact_id'] ?? null);
        $this->ensureOpportunity($companyId, $data['opportunity_id'] ?? null);
        $this->ensureCompanyUser($companyId, $data['assignee_id'] ?? null);
        unset($data['company_id']);
        $data['priority'] ??= 'normal';
        $data['status'] ??= 'open';

        $record = $task ?: new CRMTask(['company_id' => $companyId, 'created_by' => $user->id]);
        $record->fill($data)->save();

        return $record->load('contact', 'opportunity', 'assignee');
    }

    public function completeTask(User $user, CRMTask $task): CRMTask
    {
        $this->policy->ensureOwned($user, $task);
        $task->update(['status' => 'completed', 'completed_at' => now()]);

        return $task->refresh()->load('contact', 'opportunity', 'assignee');
    }

    public function saveSegment(User $user, array $data, ?CRMSegment $segment = null): CRMSegment
    {
        $companyId = $segment ? $this->policy->ensureOwned($user, $segment) : $this->companyId($user, null, 'crm.create', $data['company_id'] ?? null);
        $this->validTagIds($companyId, $data['criteria']['tag_ids'] ?? []);
        unset($data['company_id']);

        return $segment ? tap($segment)->update($data) : CRMSegment::create(['company_id' => $companyId] + $data);
    }

    public function segmentContacts(User $user, CRMSegment $segment)
    {
        $this->policy->ensureOwned($user, $segment, 'crm.view');

        return $this->applySegmentCriteria(CRMContact::with('tags', 'owner')->where('company_id', $segment->company_id), $segment->criteria)->get();
    }

    public function saveCampaign(User $user, array $data, ?CRMCampaign $campaign = null): CRMCampaign
    {
        $companyId = $campaign ? $this->policy->ensureOwned($user, $campaign) : $this->companyId($user, null, 'crm.create', $data['company_id'] ?? null);
        $this->ensureSegment($companyId, $data['segment_id'] ?? null);
        unset($data['company_id']);

        $record = $campaign ?: new CRMCampaign(['company_id' => $companyId]);
        $data['provider'] ??= 'manual';
        $data['status'] ??= 'draft';
        $record->fill($data)->save();

        return $record->load('segment', 'events.contact');
    }

    public function recordCampaignEvent(User $user, string $provider, array $data): CRMCampaignEvent
    {
        $campaign = $this->findCampaignForEvent($provider, $data);
        $this->policy->ensureOwned($user, $campaign, 'crm.edit');
        $contactId = $data['contact_id'] ?? $this->findContactIdByEmail($campaign->company_id, $data['email'] ?? null);

        if ($contactId) {
            $this->ensureContact($campaign->company_id, $contactId);
        }

        return CRMCampaignEvent::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contactId,
            'provider' => $provider,
            'event_type' => $data['event_type'],
            'payload' => $data['payload'] ?? $data,
            'occurred_at' => $data['occurred_at'] ?? now(),
        ])->load('campaign', 'contact');
    }

    public function reports(User $user, Request $request, string $type): array
    {
        $companyId = $this->companyId($user, $request, 'crm.export');

        return match ($type) {
            'conversion-rate' => $this->conversionRate($companyId),
            'pipeline-value' => $this->pipelineValue($companyId),
            'rep-performance' => $this->repPerformance($companyId),
            default => abort(404, 'Unknown CRM report.'),
        };
    }

    public function delete(User $user, Model $record): void
    {
        $this->policy->ensureOwned($user, $record, 'crm.delete');
        if ($record instanceof CRMPipelineStage && $record->opportunities()->exists()) {
            throw ValidationException::withMessages(['stage' => ['Move opportunities before deleting this pipeline stage.']]);
        }

        $record->delete();
    }

    public function listNotes(User $user, Request $request): \Illuminate\Support\Collection
    {
        $companyId = $this->companyId($user, $request);
        $query = CRMNote::with('user', 'contact', 'opportunity')
            ->where('company_id', $companyId)
            ->when($request->filled('contact_id'), fn ($q) => $q->where('contact_id', $request->integer('contact_id')))
            ->when($request->filled('opportunity_id'), fn ($q) => $q->where('opportunity_id', $request->integer('opportunity_id')))
            ->orderByDesc('is_pinned')
            ->latest();

        return $query->get();
    }

    public function saveNote(User $user, array $data, ?CRMNote $note = null): CRMNote
    {
        $companyId = $note
            ? $this->policy->ensureOwned($user, $note)
            : $this->resolveLinkedCompany($user, $data, 'crm.create');
        $this->assertCompanyAccess($user, $companyId, $note ? 'crm.edit' : 'crm.create');
        $this->ensureContact($companyId, $data['contact_id'] ?? null);
        $this->ensureOpportunity($companyId, $data['opportunity_id'] ?? null);
        unset($data['company_id']);

        $record = $note ?: new CRMNote(['company_id' => $companyId, 'user_id' => $user->id]);
        $record->fill($data)->save();

        return $record->load('user', 'contact', 'opportunity');
    }

    public function pinNote(User $user, CRMNote $note): CRMNote
    {
        $this->policy->ensureOwned($user, $note, 'crm.edit');
        $note->update(['is_pinned' => ! $note->is_pinned]);

        return $note->refresh()->load('user', 'contact', 'opportunity');
    }

    public function deleteNote(User $user, CRMNote $note): void
    {
        $this->policy->ensureOwned($user, $note, 'crm.delete');
        $note->delete();
    }

    public function trashedContacts(User $user, Request $request): \Illuminate\Support\Collection
    {
        $companyId = $this->companyId($user, $request);

        return CRMContact::onlyTrashed()->with('owner', 'tags')->where('company_id', $companyId)->latest('deleted_at')->get();
    }

    public function restoreContact(User $user, int $id): CRMContact
    {
        $contact = CRMContact::withTrashed()->findOrFail($id);
        $this->policy->ensureOwned($user, $contact, 'crm.edit');
        $contact->restore();

        return $contact->refresh()->load('owner', 'salesContact', 'tags');
    }

    public function trashedOpportunities(User $user, Request $request): \Illuminate\Support\Collection
    {
        $companyId = $this->companyId($user, $request);

        return CRMOpportunity::onlyTrashed()->with('contact', 'stage', 'owner')->where('company_id', $companyId)->latest('deleted_at')->get();
    }

    public function restoreOpportunity(User $user, int $id): CRMOpportunity
    {
        $opportunity = CRMOpportunity::withTrashed()->findOrFail($id);
        $this->policy->ensureOwned($user, $opportunity, 'crm.edit');
        $opportunity->restore();

        return $opportunity->refresh()->load('contact.tags', 'stage', 'owner');
    }

    public function bulkContacts(User $user, string $action, array $ids, array $payload, ?int $requestedCompanyId = null): array
    {
        $companyId = $this->companyId($user, null, 'crm.edit', $requestedCompanyId);
        $contacts = CRMContact::where('company_id', $companyId)->whereIn('id', $ids)->get();

        match ($action) {
            'delete' => $contacts->each(fn ($c) => $c->delete()),
            'tag' => $contacts->each(fn ($c) => $c->tags()->syncWithoutDetaching($this->validTagIds($companyId, $payload['tag_ids'] ?? []))),
            'untag' => $contacts->each(fn ($c) => $c->tags()->detach($payload['tag_ids'] ?? [])),
            'assign' => $this->bulkAssign($companyId, $ids, $payload),
            'status' => CRMContact::where('company_id', $companyId)->whereIn('id', $ids)->update(['status' => $payload['status']]),
            default => throw \Illuminate\Validation\ValidationException::withMessages(['action' => ['Unknown bulk action.']]),
        };

        return ['affected' => $contacts->count()];
    }

    public function mergeContacts(User $user, CRMContact $primary, CRMContact $secondary): CRMContact
    {
        $companyId = $this->policy->ensureOwned($user, $primary, 'crm.edit');
        $this->policy->ensureOwned($user, $secondary, 'crm.edit');

        if ((int) $primary->company_id !== (int) $secondary->company_id) {
            throw \Illuminate\Validation\ValidationException::withMessages(['secondary_id' => ['Both contacts must belong to the same company.']]);
        }

        return DB::transaction(function () use ($primary, $secondary) {
            CRMActivity::where('contact_id', $secondary->id)->update(['contact_id' => $primary->id]);
            CRMOpportunity::where('contact_id', $secondary->id)->update(['contact_id' => $primary->id]);
            CRMTask::where('contact_id', $secondary->id)->update(['contact_id' => $primary->id]);
            CRMNote::where('contact_id', $secondary->id)->update(['contact_id' => $primary->id]);

            $primary->tags()->syncWithoutDetaching($secondary->tags()->pluck('crm_tags.id'));

            foreach (['email', 'phone', 'company_name', 'region', 'source'] as $field) {
                if (! $primary->$field && $secondary->$field) {
                    $primary->$field = $secondary->$field;
                }
            }
            $primary->save();

            $secondary->forceDelete();
            $this->recomputeLeadScore($primary->fresh());

            return $primary->refresh()->load('owner', 'salesContact', 'tags');
        });
    }

    public function recomputeLeadScore(CRMContact $contact): CRMContact
    {
        $activitiesCount = CRMActivity::where('contact_id', $contact->id)->count();
        $opportunities = CRMOpportunity::where('contact_id', $contact->id)->get();
        $oppsCount = $opportunities->count();
        $totalValue = $opportunities->sum('value');
        $allTasks = CRMTask::where('contact_id', $contact->id)->get();
        $totalTasks = $allTasks->count();
        $completedTasks = $allTasks->where('status', 'completed')->count();
        $lastActivity = CRMActivity::where('contact_id', $contact->id)->latest('occurred_at')->value('occurred_at');
        $daysSinceLast = $lastActivity ? now()->diffInDays($lastActivity) : 999;

        $score = min(25, $activitiesCount * 5)
            + min(20, $oppsCount * 10)
            + (int) min(20, log10(max(1, (float) $totalValue)) * 4)
            + (int) (($totalTasks > 0 ? $completedTasks / $totalTasks : 0) * 20)
            + max(0, 15 - (int) ($daysSinceLast / 7));

        $score = max(0, min(100, $score));
        $contact->update(['lead_score' => $score, 'score_computed_at' => now()]);

        return $contact->fresh();
    }

    private function bulkAssign(int $companyId, array $ids, array $payload): void
    {
        $this->ensureCompanyUser($companyId, $payload['owner_id'] ?? null);
        CRMContact::where('company_id', $companyId)->whereIn('id', $ids)->update(['owner_id' => $payload['owner_id'] ?? null]);
    }

    private function conversionRate(int $companyId): array
    {
        $total = CRMContact::where('company_id', $companyId)->whereIn('type', ['lead', 'prospect', 'customer'])->count();
        $converted = CRMContact::where('company_id', $companyId)->whereNotNull('converted_at')->count();

        return [
            'contacts' => $total,
            'converted' => $converted,
            'conversion_rate' => $total > 0 ? round($converted / $total * 100, 2) : 0,
        ];
    }

    private function pipelineValue(int $companyId): array
    {
        return CRMOpportunity::with('stage')
            ->where('company_id', $companyId)
            ->selectRaw('stage_id, currency, count(*) opportunities, sum(value) value')
            ->groupBy('stage_id', 'currency')
            ->get()
            ->map(fn (CRMOpportunity $row) => [
                'stage' => $row->stage?->name,
                'currency' => $row->currency,
                'opportunities' => (int) $row->opportunities,
                'value' => (float) $row->value,
            ])
            ->values()
            ->all();
    }

    private function repPerformance(int $companyId): array
    {
        return CRMOpportunity::with('owner')
            ->where('company_id', $companyId)
            ->selectRaw('owner_id, count(*) opportunities, sum(value) pipeline_value, sum(case when status = "won" then value else 0 end) won_value')
            ->groupBy('owner_id')
            ->get()
            ->map(fn (CRMOpportunity $row) => [
                'rep' => $row->owner?->name ?? 'Unassigned',
                'opportunities' => (int) $row->opportunities,
                'pipeline_value' => (float) $row->pipeline_value,
                'won_value' => (float) $row->won_value,
            ])
            ->values()
            ->all();
    }

    private function companyId(User $user, ?Request $request = null, string $permission = 'crm.view', ?int $companyId = null): int
    {
        return $this->policy->companyId($user, $permission, $companyId ?? $request?->integer('company_id'));
    }

    private function applyCommonFilters(Builder $query, Request $request): void
    {
        $query->when($request->filled('search'), function (Builder $q) use ($request) {
            $search = '%'.$request->string('search')->toString().'%';
            $table = $q->getModel()->getTable();
            $q->where(function (Builder $inner) use ($search, $table) {
                match ($table) {
                    'crm_contacts' => $inner->where('name', 'like', $search)->orWhere('email', 'like', $search)->orWhere('company_name', 'like', $search),
                    'crm_opportunities', 'crm_tasks' => $inner->where('title', 'like', $search),
                    'crm_activities' => $inner->where('subject', 'like', $search),
                    default => $inner->where('name', 'like', $search),
                };
            });
        });
    }

    private function applyStageStatus(array $data, CRMPipelineStage $stage): array
    {
        if ($stage->is_won) {
            return array_merge($data, ['status' => 'won', 'won_at' => now(), 'lost_at' => null]);
        }
        if ($stage->is_lost) {
            return array_merge($data, ['status' => 'lost', 'lost_at' => now(), 'won_at' => null]);
        }

        return array_merge($data, ['status' => $data['status'] ?? 'open', 'won_at' => null, 'lost_at' => null]);
    }

    private function resolveLinkedCompany(User $user, array $data, string $permission): int
    {
        if (! empty($data['contact_id'])) {
            return $this->ensureContact(null, $data['contact_id']);
        }
        if (! empty($data['opportunity_id'])) {
            return $this->ensureOpportunity(null, $data['opportunity_id']);
        }

        return $this->companyId($user, null, $permission, $data['company_id'] ?? null);
    }

    private function ensureContact(?int $companyId, ?int $id): int
    {
        if (! $id) {
            return $companyId ?: 0;
        }
        $contact = CRMContact::findOrFail($id);
        if ($companyId && (int) $contact->company_id !== $companyId) {
            throw ValidationException::withMessages(['contact_id' => ['The selected CRM contact belongs to another company.']]);
        }

        return (int) $contact->company_id;
    }

    private function ensureOpportunity(?int $companyId, ?int $id): int
    {
        if (! $id) {
            return $companyId ?: 0;
        }
        $opportunity = CRMOpportunity::findOrFail($id);
        if ($companyId && (int) $opportunity->company_id !== $companyId) {
            throw ValidationException::withMessages(['opportunity_id' => ['The selected opportunity belongs to another company.']]);
        }

        return (int) $opportunity->company_id;
    }

    private function ensureStage(int $companyId, int $id): CRMPipelineStage
    {
        $stage = CRMPipelineStage::where('company_id', $companyId)->findOrFail($id);

        return $stage;
    }

    private function ensureSegment(int $companyId, ?int $id): void
    {
        if ($id && ! CRMSegment::where('company_id', $companyId)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['segment_id' => ['The selected CRM segment belongs to another company.']]);
        }
    }

    private function ensureSalesContact(int $companyId, ?int $id): void
    {
        if ($id && ! SalesContact::where('company_id', $companyId)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['sales_contact_id' => ['The selected sales contact belongs to another company.']]);
        }
    }

    private function ensureCompanyUser(int $companyId, ?int $id): void
    {
        if ($id && ! User::where('company_id', $companyId)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['owner_id' => ['The selected user belongs to another company.']]);
        }
    }

    private function assertCompanyAccess(User $user, int $companyId, string $permission): void
    {
        $allowedCompanyId = $this->policy->companyId($user, $permission, $companyId);
        if ((int) $allowedCompanyId !== $companyId) {
            throw ValidationException::withMessages(['company_id' => ['The selected CRM record belongs to another company.']]);
        }
    }

    private function validTagIds(int $companyId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (count($ids) !== CRMTag::where('company_id', $companyId)->whereIn('id', $ids)->count()) {
            throw ValidationException::withMessages(['tag_ids' => ['All CRM tags must belong to the company.']]);
        }

        return $ids;
    }

    private function applySegmentCriteria(Builder $query, array $criteria): Builder
    {
        return $query
            ->when(! empty($criteria['types']), fn ($q) => $q->whereIn('type', $criteria['types']))
            ->when(! empty($criteria['regions']), fn ($q) => $q->whereIn('region', $criteria['regions']))
            ->when(! empty($criteria['owner_id']), fn ($q) => $q->where('owner_id', $criteria['owner_id']))
            ->when(! empty($criteria['tag_ids']), fn ($q) => $q->whereHas('tags', fn ($tagQuery) => $tagQuery->whereIn('crm_tags.id', $criteria['tag_ids'])))
            ->when(! empty($criteria['custom_attributes']), function ($q) use ($criteria) {
                foreach ($criteria['custom_attributes'] as $key => $value) {
                    $q->where("custom_attributes->{$key}", $value);
                }
            });
    }

    private function findCampaignForEvent(string $provider, array $data): CRMCampaign
    {
        $query = CRMCampaign::query()->where('provider', $provider);
        if (! empty($data['campaign_id'])) {
            return $query->findOrFail($data['campaign_id']);
        }
        if (! empty($data['external_id'])) {
            return $query->where('external_id', $data['external_id'])->firstOrFail();
        }

        throw ValidationException::withMessages(['campaign_id' => ['A campaign_id or external_id is required for campaign webhook events.']]);
    }

    private function findContactIdByEmail(int $companyId, ?string $email): ?int
    {
        return $email ? CRMContact::where('company_id', $companyId)->where('email', $email)->value('id') : null;
    }
}
