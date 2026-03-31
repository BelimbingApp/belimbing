<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Locale\DTO;

use App\Base\Locale\Enums\LocaleSource;

final readonly class ResolvedLocale
{
    public function __construct(
        public string $locale,
        public string $language,
        public string $carbonLocale,
        public string $intlLocale,
        public string $numberLocale,
        public LocaleSource $source,
        public bool $confirmed,
        public ?string $inferredCountry = null,
    ) {}

    public function requiresConfirmation(): bool
    {
        return ! $this->confirmed;
    }
}
