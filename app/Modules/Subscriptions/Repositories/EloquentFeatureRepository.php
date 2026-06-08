<?php

namespace App\Modules\Subscriptions\Repositories;

use App\Modules\Subscriptions\Contracts\FeatureRepository;
use App\Modules\Subscriptions\Models\SubscriptionFeature;
use Illuminate\Support\Collection;

class EloquentFeatureRepository implements FeatureRepository
{
    public function grouped(): Collection
    {
        return SubscriptionFeature::orderBy('module')->orderBy('name')->get()->groupBy('module');
    }

    public function create(array $attributes): SubscriptionFeature
    {
        return SubscriptionFeature::create($attributes);
    }

    public function update(SubscriptionFeature $feature, array $attributes): SubscriptionFeature
    {
        $feature->update($attributes);

        return $feature->refresh();
    }
}
