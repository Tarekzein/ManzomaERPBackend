<?php

namespace App\Modules\Platform\Contracts;

interface TranslationProvider
{
    /** @return array<int, string> */
    public function translate(array $texts, string $sourceLocale, string $targetLocale): array;

    public function name(): string;
}
