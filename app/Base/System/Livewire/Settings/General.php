<?php

namespace App\Base\System\Livewire\Settings;

use App\Base\Settings\Livewire\SettingsForm;

final class General extends SettingsForm
{
    protected function group(): string
    {
        return 'system_general';
    }

    protected function pageTitle(): string
    {
        return __('System Settings');
    }

    protected function pageSubtitle(): string
    {
        return __('Set the installation name and authenticated session policy.');
    }
}
