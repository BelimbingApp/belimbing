<?php
namespace App\Modules\Commerce\Marketplace\Livewire\Ebay;

use App\Base\Settings\Livewire\SettingsForm;

class Settings extends SettingsForm
{
    protected function group(): string
    {
        return 'marketplace_ebay';
    }
}
