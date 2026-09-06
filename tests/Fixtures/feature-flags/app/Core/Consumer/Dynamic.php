<?php

namespace FeatureFlagFixture;

use App\Base\FeatureFlags\Services\FeatureFlags;

function dynamicFlag(FeatureFlags $flags, string $name): void
{
    $flags->enabled($name);
}
