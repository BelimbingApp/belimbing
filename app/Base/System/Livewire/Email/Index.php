<?php

namespace App\Base\System\Livewire\Email;

use App\Base\Settings\Livewire\SettingsForm;

final class Index extends SettingsForm
{
    protected function group(): string
    {
        return 'system_email';
    }

    protected function pageTitle(): string
    {
        return __('Email');
    }

    protected function pageSubtitle(): string
    {
        return __('Configure outgoing delivery and sender identity.');
    }
}
