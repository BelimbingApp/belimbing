<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Foundation\Livewire\Concerns;

use App\Base\Support\Json;

trait DecodesJsonFields
{
    protected function decodeJsonField(?string $value): ?array
    {
        return Json::decodeArray($value);
    }
}
