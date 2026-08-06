<?php
namespace App\Core\AI\Services\Pricing;

use App\Core\AI\Values\ResolvedRate;

interface PricingResolver
{
    public function resolve(?string $provider, string $model): ?ResolvedRate;
}
