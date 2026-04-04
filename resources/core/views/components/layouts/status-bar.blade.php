@php
    $localeContext = app(\App\Base\Locale\Contracts\LocaleContext::class);
    $isImpersonating = session('impersonation.original_user_id') !== null;

    $licenseeExists = \App\Modules\Core\Company\Models\Company::query()->where('id', \App\Modules\Core\Company\Models\Company::LICENSEE_ID)->exists();

    $laraActivated = \App\Modules\Core\Employee\Models\Employee::laraActivationState() === true;

    $canManageLocalization = false;

    if (auth()->check()) {
        $user = auth()->user();
        $actor = \App\Base\Authz\DTO\Actor::forUser($user);
        $canManageLocalization = app(\App\Base\Authz\Contracts\AuthorizationService::class)
            ->can($actor, 'admin.system_localization.manage')
            ->allowed;
    }
@endphp

<div class="h-6 bg-surface-bar border-t border-border-default flex items-center justify-between px-4 text-xs text-muted shrink-0">
    {{-- Left: Environment Info + Warnings (highest severity first) --}}
    <div class="flex items-center gap-4">
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
                    href="{{ route('admin.setup.lara') }}"
                    wire:navigate
                    class="text-status-warning hover:underline inline-flex items-center gap-1"
                    title="{{ __('Set up Lara') }}"
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
