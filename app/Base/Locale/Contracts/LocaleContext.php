<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Locale\Contracts;

use App\Base\Locale\DTO\ResolvedLocale;

interface LocaleContext
{
    public function currentLocale(): string;

    public function currentLanguage(): string;

    public function fallbackLocale(): string;

    public function forCarbon(): string;

    public function forIntl(): string;

    public function forNumber(): string;

    public function source(): string;

    public function isConfirmed(): bool;

    public function requiresConfirmation(): bool;

    public function inferredCountry(): ?string;

    public function state(): ResolvedLocale;
}
