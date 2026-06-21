<?php

namespace App\Modules\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Companies\Models\Company;
use App\Modules\Companies\Services\CompanyDataPrivacyService;
use App\Modules\Companies\Services\CompanyService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        $path = $privacy->export($request->user(), $company);

        return response()->download($path, "company-{$company->id}-export.zip")->deleteFileAfterSend(true);
    }

    public function erase(Request $request, Company $company, CompanyDataPrivacyService $privacy): JsonResponse
    {
        $data = $request->validate(['confirmation' => ['required', 'string']]);
        $privacy->erase($request->user(), $company, $data['confirmation']);

        return ApiResponse::success(null, 'Company data erased');
    }
}
