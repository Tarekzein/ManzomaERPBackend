<?php

namespace App\Modules\Subscriptions\Contracts;

use App\Modules\Subscriptions\Models\SubscriptionFeature;
use Illuminate\Support\Collection;

interface FeatureRepository
{
    public function grouped(): Collection;

    public function create(array $attributes): SubscriptionFeature;

    public function update(SubscriptionFeature $feature, array $attributes): SubscriptionFeature;
}
