@php
    $localeContext = app(\App\Base\Locale\Contracts\LocaleContext::class);
    $isImpersonating = session('impersonation.original_user_id') !== null;

    $licenseeExists = \App\Modules\Core\Company\Models\Company::query()->where('id', \App\Modules\Core\Company\Models\Company::LICENSEE_ID)->exists();

    $laraActivated = \App\Modules\Core\Employee\Models\Employee::laraActivationState() === true;

    $user = null;
    $canManageLocalization = false;
    $statusDiagnostics = collect();

    if (auth()->check()) {
        $user = auth()->user();
        $actor = \App\Base\Authz\DTO\Actor::forUser($user);
        $canManageLocalization = app(\App\Base\Authz\Contracts\AuthorizationService::class)
            ->can($actor, 'admin.system.localization.manage')
            ->allowed;
        $statusDiagnostics = app(\App\Base\System\Services\StatusBarDiagnostics::class)
            ->forUser($user);
    }

    $statusDiagnosticCount = $statusDiagnostics->count();
    $statusDiagnosticTop = $statusDiagnostics->first();
    $statusDiagnosticVariant = $statusDiagnosticTop?->severity;
    $statusDiagnosticClasses = $statusDiagnosticVariant?->classes() ?? [];
    $statusDiagnosticTextClass = $statusDiagnosticClasses['text'] ?? 'text-muted';
    $statusDiagnosticIcon = $statusDiagnosticVariant?->icon() ?? 'heroicon-o-information-circle';
@endphp

<div class="h-6 bg-surface-bar border-t border-border-default flex items-center justify-between px-4 text-xs text-muted shrink-0">
    {{-- Left: Environment Info + Warnings (highest severity first) --}}
    <div class="flex items-center gap-4 min-w-0 overflow-hidden">
        <span>{{ config('app.env') }}</span>
        @if(config('app.debug'))
            <span>{{ __('Debug Mode') }}</span>
        @endif
        @auth
            @if ($isImpersonating)
                <form method="POST" action="{{ route('admin.impersonate.stop') }}" class="inline-flex items-center gap-1 text-status-danger">
                    @csrf
                    <x-icon name="heroicon-o-eye" class="w-3.5 h-3.5" />
                    <span>{{ __('Viewing as :name', ['name' => auth()->user()->name]) }}</span>
                    <button type="submit" class="font-medium hover:underline ml-1">
                        {{ __('Stop') }}
                    </button>
                </form>
            @endif
            @if (!$licenseeExists)
                <a href="{{ route('admin.setup.licensee') }}" wire:navigate class="text-status-danger hover:underline flex items-center gap-1">
                    <x-icon name="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5" />
                    {{ __('Licensee not set') }}
                </a>
            @endif
            @if ($canManageLocalization && $localeContext->requiresConfirmation())
                <a href="{{ route('admin.system.localization.index') }}" wire:navigate class="text-status-warning hover:underline flex items-center gap-1">
                    <x-icon name="heroicon-o-language" class="w-3.5 h-3.5" />
                    @if ($localeContext->source() === 'licensee_address')
                        {{ __('Locale inferred: :locale', ['locale' => $localeContext->currentLocale()]) }}
                    @else
                        {{ __('Locale not confirmed') }}
                    @endif
                </a>
            @endif
            @if ($statusDiagnosticCount > 0)
                <div x-data="{ open: false }" class="relative inline-flex min-w-0">
                    <button
                        type="button"
                        @click="open = !open"
                        @keydown.escape.window="open = false"
                        class="{{ $statusDiagnosticTextClass }} hover:underline inline-flex items-center gap-1 min-w-0"
                        title="{{ __('System diagnostics') }}"
                        aria-haspopup="dialog"
                        aria-expanded="false"
                        :aria-expanded="open.toString()"
                    >
                        <x-icon name="{{ $statusDiagnosticIcon }}" class="w-3.5 h-3.5 shrink-0" />
                        <span class="truncate">
                            {{ trans_choice(':count diagnostic|:count diagnostics', $statusDiagnosticCount, ['count' => $statusDiagnosticCount]) }}
                        </span>
                    </button>

                    <div
                        x-cloak
                        x-show="open"
                        x-transition.opacity.duration.100ms
                        @click.outside="open = false"
                        class="fixed bottom-7 left-4 z-50 w-[min(32rem,calc(100vw-2rem))] overflow-hidden rounded-lg border border-border-default bg-surface-card text-ink shadow-lg"
                        role="dialog"
                        aria-label="{{ __('System diagnostics') }}"
                    >
                        <div class="flex items-center justify-between gap-3 border-b border-border-default px-3 py-2">
                            <div class="flex min-w-0 items-center gap-2">
                                <x-icon name="{{ $statusDiagnosticIcon }}" class="w-4 h-4 shrink-0 {{ $statusDiagnosticTextClass }}" />
                                <span class="truncate text-sm font-medium text-ink">{{ __('System diagnostics') }}</span>
                            </div>
                            <button
                                type="button"
                                @click="window.location.reload()"
                                class="inline-flex size-7 items-center justify-center rounded-md text-muted hover:bg-surface-subtle hover:text-ink"
                                title="{{ __('Refresh diagnostics') }}"
                                aria-label="{{ __('Refresh diagnostics') }}"
                            >
                                <x-icon name="heroicon-o-arrow-path" class="w-4 h-4" />
                            </button>
                        </div>

                        <div class="max-h-80 overflow-y-auto py-1">
                            @foreach ($statusDiagnostics as $diagnostic)
                                @php
                                    $diagnosticClasses = $diagnostic->severity->classes();
                                @endphp
                                <div class="border-b border-border-default px-3 py-2 last:border-b-0">
                                    <div class="flex items-start gap-2">
                                        <x-icon name="{{ $diagnostic->severity->icon() }}" class="mt-0.5 w-4 h-4 shrink-0 {{ $diagnosticClasses['text'] }}" />
                                        <div class="min-w-0 flex-1">
                                            <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                                <span class="text-[11px] font-medium uppercase {{ $diagnosticClasses['text'] }}">{{ $diagnostic->source }}</span>
                                                <span class="min-w-0 text-sm font-medium text-ink">{{ $diagnostic->summary }}</span>
                                            </div>
                                            @if ($diagnostic->detail !== null)
                                                <p class="mt-0.5 text-xs leading-snug text-muted">
                                                    {{ $diagnostic->detail }}
                                                    @if ($diagnostic->target !== null)
                                                        <a
                                                            href="{{ $diagnostic->target }}"
                                                            wire:navigate
                                                            class="ml-1 inline-flex align-text-bottom text-accent hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2"
                                                            title="{{ __('Open related diagnostics') }}"
                                                            aria-label="{{ __('Open related diagnostics') }}"
                                                        >
                                                            <x-icon name="heroicon-o-link" class="h-3.5 w-3.5" />
                                                        </a>
                                                    @endif
                                                </p>
                                            @elseif ($diagnostic->target !== null)
                                                <a
                                                    href="{{ $diagnostic->target }}"
                                                    wire:navigate
                                                    class="mt-0.5 inline-flex text-accent hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2"
                                                    title="{{ __('Open related diagnostics') }}"
                                                    aria-label="{{ __('Open related diagnostics') }}"
                                                >
                                                    <x-icon name="heroicon-o-link" class="h-3.5 w-3.5" />
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif

        @endauth
    </div>

    {{-- Right: Lara + Version --}}
    <div class="flex items-center gap-4">
        @auth
            @if ($laraActivated)
                <button
                    type="button"
                    @click="$dispatch('open-agent-chat')"
                    class="text-accent hover:underline inline-flex items-center gap-1"
                    title="{{ __('Open Lara chat (Ctrl+K)') }}"
                    aria-label="{{ __('Open Lara chat (Ctrl+K)') }}"
                >
                    <x-ai.lara-identity compact :show-role="false" />
                    <span x-show="laraBusy" x-cloak class="w-2 h-2 bg-accent rounded-full animate-pulse motion-reduce:animate-none motion-reduce:opacity-70"></span>
                </button>
            @else
                <a
                    href="{{ route('admin.ai.providers') }}"
                    wire:navigate
                    class="text-status-warning hover:underline inline-flex items-center gap-1"
                    title="{{ __('Activate Lara') }}"
                    aria-label="{{ __('Activate Lara') }}"
                >
                    <x-icon name="heroicon-o-sparkles" class="w-4 h-4" />
                    {{ __('Activate Lara') }}
                </a>
            @endif
        @endauth
        <span>v1.0.0</span>
    </div>
</div>
