<?php

namespace FeatureFlagFixture;

use App\Base\FeatureFlags\Services\FeatureFlags;

function readFlags(FeatureFlags $flags): void
{
    $flags->enabled('own.flag');
    $flags->enabled('dependency.flag');
    // A foreign flag must not become an implicit dependency.
    $flags->enabled('foreign.flag');
}

final class OtherToggle
{
    public function enabled(string $flag): bool
    {
        return true;
    }
}

function unrelated(OtherToggle $toggle): void
{
    $toggle->enabled('foreign.flag');
}
