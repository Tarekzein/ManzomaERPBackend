<?php

namespace App\Modules\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Companies\DTOs\CreateCompanyData;
use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Services\CompanyDataPrivacyService;
use App\Modules\Companies\Services\CompanyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function __construct(private readonly CompanyService $companies) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->companies->list(
                $request->user(),
                $request->string('search')->trim()->toString(),
                $request->integer('per_page', 15),
            ),
            'Companies loaded'
        );
    }

    public function current(Request $request): JsonResponse
    {
        abort_unless($request->user()->company, 422, 'A company is required.');

        return ApiResponse::success($request->user()->company->load('subscription.plan.features'), 'Company loaded');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'timezone' => ['required', 'timezone'],
            'locale' => ['required', 'string', 'max:10'],
            'currency' => ['required', 'string', 'size:3'],
            'plan_slug' => ['required', 'string', Rule::exists('subscription_plans', 'slug')->where('is_active', true)],
            'billing_cycle' => ['required', 'in:monthly,annual'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success(
            $this->companies->createFromAdmin(
                $request->user(),
                new CreateCompanyData($data['name'], $data['timezone'], $data['locale'], $data['currency']),
                $data['plan_slug'],
                $data['billing_cycle'],
                $data['is_active'] ?? true,
            ),
            'Company created',
            status: 201
        );
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'timezone' => ['sometimes', 'timezone'],
            'locale' => ['sometimes', 'string', 'max:10'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'settings' => ['sometimes', 'array'],
        ]);

        return ApiResponse::success($this->companies->updateSettings($request->user(), $company, $data), 'Company settings updated');
    }

    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'logo' => ['sometimes', 'nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('company-logos', 'public');
        }

        return ApiResponse::success($this->companies->updateSetup($request->user(), $data), 'Company setup saved');
    }

    public function suspend(Request $request, Company $company): JsonResponse
    {
        return ApiResponse::success($this->companies->setActive($request->user(), $company, false), 'Company suspended');
    }

    public function reactivate(Request $request, Company $company): JsonResponse
    {
        return ApiResponse::success($this->companies->setActive($request->user(), $company, true), 'Company reactivated');
    }

    public function export(Request $request, Company $company, CompanyDataPrivacyService $privacy)
    {
        return $privacy->export($request->user(), $company);
    }

    public function erase(Request $request, Company $company, CompanyDataPrivacyService $privacy): JsonResponse
    {
        $data = $request->validate(['confirmation' => ['required', 'string']]);
        $privacy->erase($request->user(), $company, $data['confirmation']);

        return ApiResponse::success(null, 'Company data erased');
    }
}
