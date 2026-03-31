<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

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
