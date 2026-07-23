@php
    $tzService = app(\App\Base\DateTime\Contracts\DateTimeDisplayService::class);
    $tzMode = $tzService->currentMode();
    $tzLabel = $tzMode->label();
    $companyTz = $tzService->currentCompanyTimezone();
    $companyTzExplicit = $tzService->isCompanyTimezoneExplicit();
    $companyTimezoneSettingsUrl = null;
    $theme = 'system';

    if (auth()->check()) {
        $user = auth()->user();
        $companyId = $user->getCompanyId();
        $companyTimezoneSettingsUrl = $companyId !== null
            ? route('admin.companies.show', $companyId)
            : null;
        $theme = (string) app(\App\Base\Settings\Contracts\SettingsService::class)->get(
            'ui.theme',
            \App\Base\Settings\DTO\Scope::user((int) $user->getKey(), $user->getCompanyId()),
        );
    }
@endphp

<div class="h-7 bg-surface-bar border-b border-border-default flex items-center justify-between px-2 shrink-0 z-10">
    {{-- Left: Sidebar toggle + App title --}}
    <div class="flex items-center gap-4">
        <button
            type="button"
            @click="$dispatch('toggle-sidebar')"
            class="inline-flex items-center justify-center w-8 h-8 rounded-sm text-accent hover:bg-surface-subtle transition"
            aria-label="{{ __('Toggle sidebar') }}"
            title="{{ __('Toggle sidebar') }}"
        >
            <x-icon name="heroicon-o-bars-3" class="w-5 h-5" />
        </button>
        <a
            href="{{ route('home') }}"
            class="flex items-center gap-2 text-ink hover:opacity-90 transition"
            wire:navigate
            aria-label="{{ config('app.name', 'Belimbing') }}"
        >
            <x-app-logo />
        </a>
    </div>

    {{-- Right: Notifications + Timezone selector + Theme selector --}}
    <div class="flex items-center gap-3" x-data="{
        theme: @js($theme),
        themeSaving: false,
        themeMedia: null,
        tzOpen: false,
        tzMode: @js($tzMode->value),
        tzLabel: @js($tzLabel),
        companyTz: @js($companyTz),
        companyTzExplicit: @js($companyTzExplicit),
        companyTimezoneSettingsUrl: @js($companyTimezoneSettingsUrl),
        browserTz: Intl.DateTimeFormat().resolvedOptions().timeZone,
        tzSaving: false,
        init() {
            this.theme = ['light', 'dark', 'system'].includes(this.theme) ? this.theme : 'system';
            localStorage.setItem('theme', this.theme);
            this.applyTheme();
            this.themeMedia = matchMedia('(prefers-color-scheme: dark)');
            this.themeMedia.addEventListener('change', () => {
                if (this.theme === 'system') this.applyTheme();
            });
            window.addEventListener('theme-changed', event => {
                this.theme = event.detail?.theme ?? 'system';
                localStorage.setItem('theme', this.theme);
                this.applyTheme();
            });
        },
        applyTheme() {
            const dark = this.theme === 'dark'
                || (this.theme === 'system' && matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', dark);
        },
        setTheme(theme) {
            if (this.themeSaving || !['light', 'dark', 'system'].includes(theme)) return;
            const previousTheme = this.theme;
            this.theme = theme;
            localStorage.setItem('theme', theme);
            this.applyTheme();
            @auth
                this.themeSaving = true;
                fetch('{{ route('theme.set') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content },
                    body: JSON.stringify({ theme }),
                })
                .then(r => r.ok ? r.json() : Promise.reject(r))
                .catch(() => {
                    this.theme = previousTheme;
                    localStorage.setItem('theme', previousTheme);
                    this.applyTheme();
                })
                .finally(() => { this.themeSaving = false; });
            @endauth
        },
        tzDisplay(mode) {
            if (mode === 'local') return this.browserTz;
            if (mode === 'utc') return 'UTC · Y-m-d H:i:s';
            return this.companyTzExplicit ? this.companyTz : '{{ __('(not set)') }}';
        },
        tzCity(tz) {
            if (!tz) return tz;
            const parts = tz.split('/');
            return parts[parts.length - 1].replace(/_/g, ' ');
        },
        tzCompact(mode) {
            if (mode === 'local') return this.tzCity(this.browserTz);
            if (mode === 'utc') return 'UTC';
            return this.companyTzExplicit ? this.tzCity(this.companyTz) : '{{ __('(not set)') }}';
        },
        goToCompanyTimezoneSettings() {
            window.location.href = this.companyTimezoneSettingsUrl;
        },
        setTz(mode) {
            if (this.tzSaving || mode === this.tzMode) { this.tzOpen = false; return; }
            this.tzSaving = true;
            fetch('{{ route('timezone.set') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content },
                body: JSON.stringify({ mode }),
            })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(data => {
                this.tzMode = data.mode;
                this.tzLabel = data.label;
                if (data.company_timezone) this.companyTz = data.company_timezone;
                this.companyTzExplicit = data.company_timezone_explicit ?? this.companyTzExplicit;
                this.tzOpen = false;
                window.location.reload();
            })
            .catch(() => { this.tzSaving = false; });
        }
    }">
        @auth
            <livewire:notifications.bell />
        @endauth

        {{-- Timezone Mode Selector --}}
        @auth
            <div class="relative" @click.outside="tzOpen = false" @keydown.escape.window="tzOpen = false">
                <button
                    type="button"
                    @click="(tzMode === 'company' && !companyTzExplicit && companyTimezoneSettingsUrl) ? goToCompanyTimezoneSettings() : tzOpen = !tzOpen"
                    :disabled="tzSaving"
                    class="inline-flex items-center gap-1 px-1.5 py-0.5 text-xs rounded hover:bg-surface-subtle transition-colors"
                    :class="[
                        tzSaving && 'opacity-50 cursor-wait',
                        (tzMode === 'company' && !companyTzExplicit) ? 'text-status-warning hover:text-status-warning' : 'text-muted hover:text-ink',
                    ]"
                    aria-haspopup="true"
                    :aria-expanded="tzOpen"
                    aria-label="{{ __('Select timezone display mode') }}"
                    :title="tzLabel + ' — ' + tzDisplay(tzMode)"
                >
                    <x-icon name="heroicon-o-clock" class="w-3.5 h-3.5" />
                    <span x-text="tzCompact(tzMode)"></span>
                    <x-icon name="heroicon-m-chevron-down" class="w-3 h-3 opacity-60" />
                </button>

                <div
                    x-show="tzOpen"
                    x-transition:enter="transition ease-out duration-100"
                    x-transition:enter-start="opacity-0 scale-95"
                    x-transition:enter-end="opacity-100 scale-100"
                    x-transition:leave="transition ease-in duration-75"
                    x-transition:leave-start="opacity-100 scale-100"
                    x-transition:leave-end="opacity-0 scale-95"
                    x-cloak
                    class="absolute right-0 top-full mt-0 w-64 bg-surface-card border border-border-default rounded-lg shadow-lg py-1 z-50"
                    role="menu"
                >
                    @foreach (\App\Base\DateTime\Enums\TimezoneMode::cases() as $mode)
                        <button
                            type="button"
                            @click="setTz('{{ $mode->value }}')"
                            class="w-full flex items-center gap-2 px-3 py-1.5 text-xs text-left hover:bg-surface-subtle transition-colors"
                            :class="tzMode === '{{ $mode->value }}' ? 'text-ink font-medium' : 'text-muted'"
                            role="menuitem"
                        >
                            <x-icon
                                name="heroicon-m-check"
                                class="w-3.5 h-3.5 shrink-0"
                                x-bind:class="tzMode === '{{ $mode->value }}' ? 'opacity-100' : 'opacity-0'"
                            />
                            <span class="flex flex-col leading-tight">
                                <span>{{ $mode->description() }}</span>
                                <span class="text-muted text-[10px]" x-text="tzDisplay('{{ $mode->value }}')"></span>
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endauth

        <x-ui.segmented-control
            :options="[
                ['value' => 'light', 'label' => __('Light'), 'icon' => 'heroicon-o-sun'],
                ['value' => 'dark', 'label' => __('Dark'), 'icon' => 'heroicon-o-moon'],
                ['value' => 'system', 'label' => __('System'), 'icon' => 'heroicon-o-computer-desktop'],
            ]"
            :value="$theme"
            :label="__('Theme')"
            :show-labels="false"
            x-model="theme"
            @segmented-control-change="
                setTheme($event.detail.value);
            "
        />
    </div>
</div>
