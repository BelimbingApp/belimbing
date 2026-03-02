<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <nav class="flex flex-col space-y-1">
            <a href="{{ route('profile.edit') }}" class="px-4 py-2 text-sm rounded-lg hover:bg-surface-subtle text-accent transition-colors {{ request()->routeIs('profile.edit') ? 'bg-surface-subtle' : '' }}">
                {{ __('Profile') }}
            </a>
            <a href="{{ route('password.edit') }}" class="px-4 py-2 text-sm rounded-lg hover:bg-surface-subtle text-accent transition-colors {{ request()->routeIs('password.edit') ? 'bg-surface-subtle' : '' }}">
                {{ __('Password') }}
            </a>
            <a href="{{ route('appearance.edit') }}" class="px-4 py-2 text-sm rounded-lg hover:bg-surface-subtle text-accent transition-colors {{ request()->routeIs('appearance.edit') ? 'bg-surface-subtle' : '' }}">
                {{ __('Appearance') }}
            </a>
        </nav>
    </div>

    <hr class="md:hidden border-border-default" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <h2 class="text-2xl font-semibold text-ink">{{ $heading ?? '' }}</h2>
        <p class="text-muted">{{ $subheading ?? '' }}</p>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
