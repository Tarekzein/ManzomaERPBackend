<?php

namespace App\Modules\Projects\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

abstract class ProjectJsonResource extends JsonResource
{
    protected function date($value): ?string
    {
        return $value?->toDateString();
    }

    protected function dateTime($value): ?string
    {
        return $value?->toISOString();
    }

    protected function user(string $relation)
    {
        return UserSummaryResource::make($this->whenLoaded($relation));
    }

    protected function loadedCollection(string $resource, string $relation)
    {
        return $resource::collection($this->whenLoaded($relation));
    }

    protected function floatSum(string $attribute, string $relation, string $column): float
    {
        return (float) ($this->{$attribute} ?? $this->{$relation}->sum($column));
    }
}
