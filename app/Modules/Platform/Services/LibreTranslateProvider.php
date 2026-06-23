<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\TranslationProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LibreTranslateProvider implements TranslationProvider
{
    public function translate(array $texts, string $sourceLocale, string $targetLocale): array
    {
        if ($texts === []) {
            return [];
        }

        $config = config('services.translation.libretranslate');
        $payload = [
            'q' => array_values($texts),
            'source' => $sourceLocale,
            'target' => $targetLocale,
            'format' => 'text',
        ];

        if ($config['api_key'] ?? null) {
            $payload['api_key'] = $config['api_key'];
        }

        $response = Http::acceptJson()
            ->timeout((int) ($config['timeout'] ?? 10))
            ->retry(2, 200, throw: false)
            ->post(rtrim((string) $config['url'], '/').'/translate', $payload);

        if (! $response->successful()) {
            throw new RuntimeException("LibreTranslate returned HTTP {$response->status()}.");
        }

        $translated = $response->json('translatedText');
        $translated = is_array($translated) ? $translated : [$translated];

        if (count($translated) !== count($texts)) {
            throw new RuntimeException('LibreTranslate returned an unexpected translation count.');
        }

        return array_map(fn ($value) => (string) $value, $translated);
    }

    public function name(): string
    {
        return 'libretranslate';
    }
}
