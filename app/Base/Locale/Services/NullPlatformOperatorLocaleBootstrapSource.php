<?php

namespace App\Base\Locale\Services;

use App\Base\Locale\Contracts\PlatformOperatorLocaleBootstrapSource;
use App\Base\Locale\DTO\PlatformOperatorLocaleBootstrap;

class NullPlatformOperatorLocaleBootstrapSource implements PlatformOperatorLocaleBootstrapSource
{
    public function resolve(): ?PlatformOperatorLocaleBootstrap
    {
        return null;
    }
}
