<?php

namespace App\Modules\Core\User\Livewire\Settings;

use App\Base\Foundation\Services\LandingPageResolver;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Profile extends Component
{
    public string $name = '';

    public string $email = '';

    /**
     * Menu item id to land on after login; empty string means the default.
     */
    public string $landingMenuId = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $user = Auth::user();
        $this->landingMenuId = (string) app(SettingsService::class)->get(
            LandingPageResolver::SETTING_KEY,
            Scope::user((int) $user->getKey(), $user->getCompanyId()),
        );
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(LandingPageResolver $landing): void
    {
        $user = Auth::user();

        try {
            $validated = $this->validate([
                'name' => ['required', 'string', 'max:255'],

                'email' => [
                    'required',
                    'string',
                    'lowercase',
                    'email',
                    'max:255',
                    Rule::unique(User::class)->ignore($user->id),
                ],

                'landingMenuId' => [
                    'nullable',
                    'string',
                    Rule::in(['', ...array_keys($landing->optionsFor($user))]),
                ],
            ]);
        } catch (ValidationException $exception) {
            Session::flash('error', __('Profile was not saved. Review the highlighted fields.'));

            throw $exception;
        }

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $settings = app(SettingsService::class);
        $scope = Scope::user((int) $user->getKey(), $user->getCompanyId());

        if ($this->landingMenuId === '') {
            $settings->forget(LandingPageResolver::SETTING_KEY, $scope);
        } else {
            $settings->set(LandingPageResolver::SETTING_KEY, $this->landingMenuId, $scope);
        }

        Session::flash('success', __('Profile saved.'));
    }

    /**
     * Landing-page choices: every navigable menu item visible to the user.
     *
     * @return array<string, string> Menu item id => label
     */
    private function landingOptions(): array
    {
        $options = [];

        foreach (app(LandingPageResolver::class)->optionsFor(Auth::user()) as $id => $item) {
            $options[$id] = $item['label'];
        }

        asort($options);

        return $options;
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    public function render(): View
    {
        return view('livewire.profile.profile', [
            'landingOptions' => $this->landingOptions(),
        ]);
    }
}
