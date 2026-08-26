@php
    $activeTab = match (true) {
        request()->routeIs('password.edit') => 'password',
        request()->routeIs('appearance.edit') => 'appearance',
        default => 'profile',
    };

    $settingsTabs = [
        ['id' => 'profile', 'label' => __('Profile'), 'href' => route('profile.edit')],
        ['id' => 'password', 'label' => __('Password'), 'href' => route('password.edit')],
        ['id' => 'appearance', 'label' => __('Appearance'), 'href' => route('appearance.edit')],
    ];
@endphp

<div class="w-full">
    <x-ui.tabs
        tabs-id="user-settings-tabs"
        :tabs="$settingsTabs"
        :default="$activeTab"
        persistence="none"
    >
        @foreach ($settingsTabs as $tab)
            <x-ui.tab :id="$tab['id']">
                @if ($activeTab === $tab['id'])
                    <h2 class="text-2xl font-semibold text-ink">{{ $heading ?? '' }}</h2>
                    <p class="text-muted">{{ $subheading ?? '' }}</p>

                    <div class="mt-5 w-full max-w-lg">
                        {{ $slot }}
                    </div>
                @endif
            </x-ui.tab>
        @endforeach
    </x-ui.tabs>
</div>
