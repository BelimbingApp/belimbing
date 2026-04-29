<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Commerce\Marketplace\Livewire\Ebay;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Livewire\Admin\Index as SettingsIndex;

class Settings extends SettingsIndex
{
    public function mount(SettingsService $settings, ?string $group = null): void
    {
        parent::mount($settings, 'marketplace_ebay');
    }
}
