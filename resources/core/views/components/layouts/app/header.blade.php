@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ app(\App\Base\Locale\Contracts\LocaleContext::class)->forIntl() }}" class="dark">
    <head>
        <title>{{ isset($title) && $title ? $title . ' — ' . config('app.name') : config('app.name') }}</title>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-surface-card">
        <!-- Header -->
        <div class="navbar bg-surface-card border-b border-border-default">
            <a href="{{ route('dashboard') }}" class="flex items-center space-x-2 me-5" wire:navigate>
                <x-app-logo />
            </a>

            <!-- Desktop Navigation -->
            <div class="hidden lg:flex">
                <ul class="menu menu-horizontal px-1">
                    <li>
                        <a href="{{ route('dashboard') }}" wire:navigate class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                            <x-icon name="heroicon-o-squares-2x2" class="w-5 h-5" />
                            {{ __('Dashboard') }}
                        </a>
                    </li>
                </ul>
            </div>

            <div class="flex-1"></div>

            <!-- Action Buttons -->
            <div class="hidden lg:flex gap-2">
                <a href="#" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg hover:bg-surface-subtle text-accent transition-colors" title="{{ __('Search') }}" aria-label="{{ __('Search') }}">
                    <x-icon name="heroicon-o-magnifying-glass" class="w-5 h-5" />
                    <span class="sr-only">{{ __('Search') }}</span>
                </a>
                <a href="https://github.com/BelimbingApp/belimbing" target="_blank" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg hover:bg-surface-subtle text-accent transition-colors" title="{{ __('Repository') }}" aria-label="{{ __('Repository') }}">
                    <x-icon name="heroicon-o-folder" class="w-5 h-5" />
                    <span class="sr-only">{{ __('Repository') }}</span>
                </a>
                <a href="https://github.com/BelimbingApp/belimbing/tree/main/docs" target="_blank" class="inline-flex items-center gap-1 px-3 py-1.5 text-sm rounded-lg hover:bg-surface-subtle text-accent transition-colors" title="{{ __('Documentation') }}" aria-label="{{ __('Documentation') }}">
                    <x-icon name="heroicon-o-book-open" class="w-5 h-5" />
                    <span class="sr-only">{{ __('Documentation') }}</span>
                </a>
            </div>

            <!-- User Menu -->
            <x-ui.user-menu :user="auth()->user()" />
        </div>

        <!-- Mobile Sidebar -->
        <div class="drawer lg:hidden">
            <input id="mobile-drawer" type="checkbox" class="drawer-toggle" />
            <div class="drawer-content">
                {{ $slot }}
            </div>
            <div class="drawer-side">
                <label for="mobile-drawer" class="drawer-overlay"></label>
                <aside class="w-64 min-h-full bg-surface-subtle border-r border-border-default p-4">
                    <label for="mobile-drawer" class="w-10 h-10 inline-flex items-center justify-center rounded-lg hover:bg-surface-subtle transition-colors mb-4" aria-label="{{ __('Close menu') }}">
                        <x-icon name="heroicon-o-x-mark" class="w-6 h-6" />
                        <span class="sr-only">{{ __('Close menu') }}</span>
                    </label>
                    <ul class="flex flex-col space-y-1 w-full">
                        <li>
                            <a href="{{ route('dashboard') }}" wire:navigate class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                                <x-icon name="heroicon-o-squares-2x2" class="w-5 h-5" />
                                {{ __('Dashboard') }}
                            </a>
                        </li>
                    </ul>
                    <div class="border-t border-border-input my-4"></div>
                    <ul class="flex flex-col space-y-1 w-full">
                        <li>
                            <a href="https://github.com/BelimbingApp/belimbing" target="_blank">
                                <x-icon name="heroicon-o-folder" class="w-5 h-5" />
                                {{ __('Repository') }}
                            </a>
                        </li>
                        <li>
                            <a href="https://github.com/BelimbingApp/belimbing/tree/main/docs" target="_blank">
                                <x-icon name="heroicon-o-book-open" class="w-5 h-5" />
                                {{ __('Documentation') }}
                            </a>
                        </li>
                    </ul>
                </aside>
            </div>
        </div>

        <!-- Main Content -->
        <main class="flex-1 p-6">
            {{ $slot }}
        </main>
    </body>
</html>
