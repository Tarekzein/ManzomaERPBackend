<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\TranslationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TranslationController extends Controller
{
    public function __invoke(Request $request, TranslationService $translations): JsonResponse
    {
        $locales = array_keys(config('erp.locales', []));
        $data = $request->validate([
            'source_locale' => ['required', 'string', 'in:'.implode(',', $locales)],
            'target_locale' => ['required', 'string', 'different:source_locale', 'in:'.implode(',', $locales)],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.key' => ['required', 'string', 'max:200', 'distinct'],
            'items.*.text' => ['required', 'string', 'max:5000'],
        ]);

        $characters = collect($data['items'])->sum(fn (array $item) => mb_strlen($item['text']));
        if ($characters > 10000) {
            throw ValidationException::withMessages(['items' => ['The translation batch may not exceed 10,000 characters.']]);
        }

        return ApiResponse::success(
            $translations->translate($request->user(), $data['source_locale'], $data['target_locale'], $data['items']),
            'Translations loaded',
        );
    }
}
