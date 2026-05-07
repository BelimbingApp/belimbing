<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Inventory\Livewire;

use App\Base\Settings\Livewire\SettingsForm;

class Settings extends SettingsForm
{
    protected function group(): string
    {
        return 'commerce';
    }
}
