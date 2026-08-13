<?php

namespace Tests\Feature;

use App\Modules\Companies\Models\Company;
use App\Modules\Platform\Services\DocumentNumberService;
use App\Modules\Sales\Models\SalesContact;
use App\Modules\Sales\Models\SalesQuotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequences_start_after_existing_documents_and_are_isolated_by_company(): void
    {
        $company = Company::factory()->create();
        $contact = SalesContact::create([
            'company_id' => $company->id,
            'type' => 'customer',
            'name' => 'Existing customer',
            'currency' => 'EGP',
        ]);
        SalesQuotation::create([
            'company_id' => $company->id,
            'customer_id' => $contact->id,
            'number' => 'SQ-2026-000007',
            'quote_date' => '2026-08-01',
            'status' => 'draft',
            'currency' => 'EGP',
        ]);

        $numbers = app(DocumentNumberService::class);
        $this->assertSame('SQ-2026-000008', $numbers->next($company->id, 'SQ', '2026'));
        $this->assertSame('SQ-2026-000009', $numbers->next($company->id, 'SQ', '2026'));

        $other = Company::factory()->create();
        $this->assertSame('SQ-2026-000001', $numbers->next($other->id, 'SQ', '2026'));
    }

    public function test_scoped_sequences_are_independent_without_weakening_company_wide_uniqueness(): void
    {
        $company = Company::factory()->create();
        $numbers = app(DocumentNumberService::class);

        $this->assertSame('RCP-R10-2026-000001', $numbers->next($company->id, 'RCP', '2026', 'pos-register:10'));
        $this->assertSame('RCP-R10-2026-000002', $numbers->next($company->id, 'RCP', '2026', 'pos-register:10'));
        $this->assertSame('RCP-R20-2026-000001', $numbers->next($company->id, 'RCP', '2026', 'pos-register:20'));

        $this->assertSame(2, \App\Modules\Platform\Models\DocumentSequence::query()
            ->where('company_id', $company->id)
            ->where('prefix', 'RCP')
            ->count());
    }
}
