<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\Pricing;

use App\Modules\Core\AI\Values\ResolvedRate;

interface PricingResolver
{
    public function resolve(?string $provider, string $model): ?ResolvedRate;
}
