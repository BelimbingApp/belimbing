<?php
namespace App\Modules\Core\AI\Services\Pricing;

use App\Modules\Core\AI\Values\ResolvedRate;

interface PricingResolver
{
    public function resolve(?string $provider, string $model): ?ResolvedRate;
}
