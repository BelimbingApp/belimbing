@php
    $tzService = app(\App\Base\DateTime\Contracts\DateTimeDisplayService::class);
    $tzMode = $tzService->currentMode();
    $tzLabel = match ($tzMode) {
        \App\Base\DateTime\Enums\TimezoneMode::COMPANY => __('Company'),
        \App\Base\DateTime\Enums\TimezoneMode::LOCAL => __('Local'),
        \App\Base\DateTime\Enums\TimezoneMode::UTC => __('UTC'),
    };
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

    {{-- Right: Timezone toggle + Theme toggle --}}
    <div class="flex items-center gap-3" x-data="{
        theme: localStorage.getItem('theme') || 'light',
        tzLabel: @js($tzLabel),
        tzCycling: false,
        init() {
            if (this.theme === 'dark') {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        },
        cycleTz() {
            if (this.tzCycling) return;
            this.tzCycling = true;
            fetch('{{ route('timezone.cycle') }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content },
            })
            .then(r => r.ok ? r.json() : Promise.reject(r))
            .then(data => {
                this.tzLabel = data.label;
                window.location.reload();
            })
            .catch(() => { this.tzCycling = false; });
        }
    }">
        {{-- Timezone Mode Toggle --}}
        @auth
            <button
                type="button"
                @click="cycleTz()"
                :disabled="tzCycling"
                class="inline-flex items-center gap-1 px-1.5 py-0.5 text-xs rounded hover:bg-surface-subtle text-muted hover:text-ink transition-colors"
                :class="tzCycling && 'opacity-50 cursor-wait'"
                title="{{ __('Cycle timezone display: Company → Local → UTC') }}"
                aria-label="{{ __('Cycle timezone display mode') }}"
            >
                <x-icon name="heroicon-o-clock" class="w-3.5 h-3.5" />
                <span x-text="tzLabel"></span>
            </button>
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
