<?php

namespace FeatureFlagFixture;

use App\Base\FeatureFlags\Services\FeatureFlags;

function unowned(FeatureFlags $flags): void
{
    $flags->enabled('own.flag');
}
