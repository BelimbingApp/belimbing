<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Locale\Contracts;

use App\Base\Locale\DTO\LicenseeLocaleBootstrap;

interface LicenseeLocaleBootstrapSource
{
    public function resolve(): ?LicenseeLocaleBootstrap;
}
