@php
    $licenseeExists = \App\Modules\Core\Company\Models\Company::query()->where('id', \App\Modules\Core\Company\Models\Company::LICENSEE_ID)->exists();

    $laraExists = \App\Modules\Core\Employee\Models\Employee::query()->where('id', \App\Modules\Core\Employee\Models\Employee::LARA_ID)->exists();
    $laraActivated = false;
    if ($laraExists && $licenseeExists) {
        $resolver = app(\App\Modules\Core\AI\Services\ConfigResolver::class);
        $configs = $resolver->resolve(\App\Modules\Core\Employee\Models\Employee::LARA_ID);
        if (count($configs) === 0) {
            $default = $resolver->resolveDefault(\App\Modules\Core\Company\Models\Company::LICENSEE_ID);
            $laraActivated = $default !== null;
        } else {
            $laraActivated = true;
        }
    }
@endphp

<div class="h-6 bg-surface-bar border-t border-border-default flex items-center justify-between px-4 text-xs text-muted shrink-0">
    {{-- Left: Environment Info + Warnings --}}
    <div class="flex items-center gap-4">
        <span>{{ config('app.env') }}</span>
        @if(config('app.debug'))
            <span>Debug Mode</span>
        @endif
        @auth
            @if (!$licenseeExists)
                <a href="{{ route('admin.setup.licensee') }}" wire:navigate class="text-status-danger hover:underline flex items-center gap-1">
                    <x-icon name="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5" />
                    {{ __('Licensee not set') }}
                </a>
            @endif
            @if (!$laraExists)
                <a href="{{ route('admin.setup.lara') }}" wire:navigate class="text-status-danger hover:underline flex items-center gap-1">
                    <x-icon name="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5" />
                    {{ __('Lara not set up') }}
                </a>
            @elseif (!$laraActivated)
                <a href="{{ route('admin.setup.lara') }}" wire:navigate class="text-status-warning hover:underline flex items-center gap-1">
                    <x-icon name="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5" />
                    {{ __('Lara not activated') }}
                </a>
            @endif
        @endauth
    </div>

    {{-- Right: Version/Time --}}
    <div class="flex items-center gap-4">
        <span>{{ now()->format('H:i') }}</span>
        <span>v1.0.0</span>
    </div>
</div>
