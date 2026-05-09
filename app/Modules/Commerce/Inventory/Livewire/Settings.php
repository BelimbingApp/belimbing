<?php
namespace App\Modules\Commerce\Inventory\Livewire;

use App\Base\Settings\Livewire\SettingsForm;

class Settings extends SettingsForm
{
    protected function group(): string
    {
        return 'commerce';
    }
}
