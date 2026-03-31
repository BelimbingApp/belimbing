@php
    // SPDX-License-Identifier: AGPL-3.0-only
    // (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

    $tzService = app(\App\Base\DateTime\Contracts\DateTimeDisplayService::class);
    $tzMode = $tzService->currentMode();
    $tzLabel = match ($tzMode) {
        \App\Base\DateTime\Enums\TimezoneMode::COMPANY => __('Company'),
        \App\Base\DateTime\Enums\TimezoneMode::LOCAL => __('Local'),
        \App\Base\DateTime\Enums\TimezoneMode::UTC => __('Stored'),
    };
    $companyTz = $tzService->currentCompanyTimezone();
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
        <h1 class="text-base font-semibold text-ink">
            Belimbing
        </h1>
    </div>

    {{-- Right: Timezone selector + Theme toggle --}}
    <div class="flex items-center gap-3" x-data="{
        theme: localStorage.getItem('theme') || 'light',
        tzOpen: false,
        tzMode: @js($tzMode->value),
        tzLabel: @js($tzLabel),
        companyTz: @js($companyTz),
        browserTz: Intl.DateTimeFormat().resolvedOptions().timeZone,
        tzSaving: false,
        init() {
            if (this.theme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        },
        tzDisplay(mode) {
            if (mode === 'local') return this.browserTz;
            if (mode === 'utc') return 'UTC · Y-m-d H:i:s';
            return this.companyTz;
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
                this.tzOpen = false;
                window.location.reload();
            })
            .catch(() => { this.tzSaving = false; });
        }
    }">
        {{-- Timezone Mode Selector --}}
        @auth
            <div class="relative" @click.outside="tzOpen = false" @keydown.escape.window="tzOpen = false">
                <button
                    type="button"
                    @click="tzOpen = !tzOpen"
                    :disabled="tzSaving"
                    class="inline-flex items-center gap-1 px-1.5 py-0.5 text-xs rounded hover:bg-surface-subtle text-muted hover:text-ink transition-colors"
                    :class="tzSaving && 'opacity-50 cursor-wait'"
                    aria-haspopup="true"
                    :aria-expanded="tzOpen"
                    aria-label="{{ __('Select timezone display mode') }}"
                >
                    <x-icon name="heroicon-o-clock" class="w-3.5 h-3.5" />
                    <span x-text="tzLabel + ' — ' + tzDisplay(tzMode)"></span>
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
                                <span>{{ match ($mode) {
                                    \App\Base\DateTime\Enums\TimezoneMode::COMPANY => __('Company'),
                                    \App\Base\DateTime\Enums\TimezoneMode::LOCAL => __('Local (browser)'),
                                    \App\Base\DateTime\Enums\TimezoneMode::UTC => __('Stored (raw)'),
                                } }}</span>
                                <span class="text-muted text-[10px]" x-text="tzDisplay('{{ $mode->value }}')"></span>
                            </span>
                        </button>
                    @endforeach
                </div>
            </div>
        @endauth

        {{-- Theme Toggle: minimal pill switch --}}
        <button
            @click="
                theme = (theme === 'dark' ? 'light' : 'dark');
                localStorage.setItem('theme', theme);
                document.documentElement.classList.toggle('dark');
            "
            class="relative w-9 h-5 rounded-full bg-border-input dark:bg-zinc-700 transition-colors hover:bg-muted/50 dark:hover:bg-zinc-600 shadow-inner"
            :aria-label="theme === 'dark' ? '{{ __('Switch to light mode') }}' : '{{ __('Switch to dark mode') }}'"
            title="{{ __('Toggle theme') }}"
            :aria-pressed="theme === 'light'"
        >
            <span
                class="absolute top-0.5 w-4 h-4 rounded-full bg-white dark:bg-white shadow transition-transform duration-200"
                :class="theme === 'dark' ? 'left-0.5' : 'left-4'"
            ></span>
        </button>
    </div>
</div>
