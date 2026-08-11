<?php

namespace App\Base\Locale\Contracts;

use App\Base\Locale\DTO\PlatformOperatorLocaleBootstrap;

interface PlatformOperatorLocaleBootstrapSource
{
    public function resolve(): ?PlatformOperatorLocaleBootstrap;
}
