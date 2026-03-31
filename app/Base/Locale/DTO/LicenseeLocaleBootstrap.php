<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Locale\DTO;

final readonly class LicenseeLocaleBootstrap
{
    public function __construct(
        public string $countryIso,
        public ?string $countryName = null,
        public ?string $languages = null,
        public ?string $currencyCode = null,
    ) {}
}
