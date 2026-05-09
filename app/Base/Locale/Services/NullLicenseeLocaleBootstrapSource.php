<?php
namespace App\Base\Locale\Services;

use App\Base\Locale\Contracts\LicenseeLocaleBootstrapSource;
use App\Base\Locale\DTO\LicenseeLocaleBootstrap;

class NullLicenseeLocaleBootstrapSource implements LicenseeLocaleBootstrapSource
{
    public function resolve(): ?LicenseeLocaleBootstrap
    {
        return null;
    }
}
