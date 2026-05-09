<?php
namespace App\Base\Locale\Contracts;

use App\Base\Locale\DTO\LicenseeLocaleBootstrap;

interface LicenseeLocaleBootstrapSource
{
    public function resolve(): ?LicenseeLocaleBootstrap;
}
