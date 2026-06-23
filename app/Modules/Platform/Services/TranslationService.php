<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Platform\Contracts\TranslationProvider;
use App\Modules\Platform\Models\CachedTranslation;
use Illuminate\Support\Facades\Log;
use Throwable;

class TranslationService
{
    public function __construct(private readonly TranslationProvider $provider) {}

    public function translate(User $user, string $sourceLocale, string $targetLocale, array $items): array
    {
        abort_unless($user->company_id, 422, 'Translation requires a company account.');

        $result = [];
        $requests = [];
        foreach ($items as $item) {
            $key = $item['key'];
            $text = trim($item['text']);
            if ($text === '' || $sourceLocale === $targetLocale) {
                $result[$key] = $item['text'];
                continue;
            }

            $hash = hash('sha256', $text);
            $requests[$hash] ??= ['text' => $text, 'keys' => []];
            $requests[$hash]['keys'][] = $key;
        }

        $cached = CachedTranslation::query()
            ->where('company_id', $user->company_id)
            ->where('source_locale', $sourceLocale)
            ->where('target_locale', $targetLocale)
            ->whereIn('source_hash', array_keys($requests))
            ->get()
            ->keyBy('source_hash');

        $missing = [];
        foreach ($requests as $hash => $request) {
            if ($translation = $cached->get($hash)) {
                foreach ($request['keys'] as $key) {
                    $result[$key] = $translation->translated_text;
                }
            } else {
                $missing[$hash] = $request;
            }
        }

        if ($missing !== []) {
            try {
                $translated = $this->provider->translate(
                    array_column(array_values($missing), 'text'),
                    $sourceLocale,
                    $targetLocale,
                );

                foreach (array_values($missing) as $index => $request) {
                    $hash = array_keys($missing)[$index];
                    $text = $translated[$index];
                    CachedTranslation::updateOrCreate(
                        [
                            'company_id' => $user->company_id,
                            'source_locale' => $sourceLocale,
                            'target_locale' => $targetLocale,
                            'source_hash' => $hash,
                        ],
                        ['translated_text' => $text, 'provider' => $this->provider->name()],
                    );
                    foreach ($request['keys'] as $key) {
                        $result[$key] = $text;
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Automatic translation failed.', [
                    'company_id' => $user->company_id,
                    'provider' => $this->provider->name(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        foreach ($items as $item) {
            $result[$item['key']] ??= $item['text'];
        }

        return ['translations' => $result, 'provider' => $this->provider->name()];
    }
}
