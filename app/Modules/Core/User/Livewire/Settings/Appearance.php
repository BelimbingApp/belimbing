<?php

namespace App\Modules\Core\User\Livewire\Settings;

use App\Base\Locale\Services\LocaleCatalog;
use App\Base\Locale\Services\LocaleSettings;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Appearance extends Component
{
    public string $theme = 'system';

    public string $locale = '';

    public function mount(LocaleSettings $locales, SettingsService $settings): void
    {
        $user = Auth::user();
        $scope = Scope::user((int) $user->getKey(), $user->getCompanyId());
        $this->theme = (string) $settings->get('ui.theme', $scope);
        $this->locale = $locales->hasUserOverride($user) ? $locales->forUser($user) : '';
    }

    public function save(
        LocaleCatalog $catalog,
        LocaleSettings $locales,
        SettingsService $settings,
    ): void {
        $this->validate([
            'theme' => ['required', 'string', Rule::in(['light', 'dark', 'system'])],
            'locale' => ['nullable', 'string', Rule::in(['', ...array_keys($catalog->options())])],
        ]);

        $user = Auth::user();
        $scope = Scope::user((int) $user->getKey(), $user->getCompanyId());

        if ($this->theme === 'system') {
            $settings->forget('ui.theme', $scope);
        } else {
            $settings->set('ui.theme', $this->theme, $scope);
        }

        if ($this->locale === '') {
            $locales->forgetForUser($user);
        } else {
            $locales->setForUser($user, $this->locale);
        }

        $this->dispatch('theme-changed', theme: $this->theme);
        Session::flash('success', __('Appearance settings saved.'));
    }

    public function render(LocaleCatalog $catalog, LocaleSettings $locales): View
    {
        return view('livewire.profile.appearance', [
            'localeOptions' => $catalog->options(),
            'installationLocale' => $catalog->label($locales->global()),
        ]);
    }
}
