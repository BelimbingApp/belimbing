<?php

use App\Modules\Core\User\Livewire\Notifications\Bell;

/** @var Bell $this */
$badgeCount = $unreadCount > 99 ? '99+' : (string) $unreadCount;
?>

<div
    class="relative"
    x-data="{ open: false }"
    @click.outside="open = false"
    @keydown.escape.window="open = false"
    wire:poll.visible.60s
>
    <button
        type="button"
        @click="open = ! open"
        class="relative inline-flex items-center justify-center w-7 h-7 rounded-sm text-muted hover:text-ink hover:bg-surface-subtle transition-colors"
        aria-haspopup="true"
        :aria-expanded="open.toString()"
        aria-expanded="false"
        aria-label="{{ $unreadCount > 0 ? trans_choice(':count unread notification|:count unread notifications', $unreadCount, ['count' => $badgeCount]) : __('Notifications') }}"
        title="{{ __('Notifications') }}"
    >
        <x-icon name="heroicon-o-bell" class="w-4 h-4" />
        @if ($unreadCount > 0)
            <span class="absolute -top-0.5 -right-0.5 min-w-[14px] h-[14px] px-0.5 inline-flex items-center justify-center rounded-full bg-accent text-accent-on text-[9px] font-semibold leading-none tabular-nums">
                {{ $badgeCount }}
            </span>
        @endif
    </button>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        class="absolute right-0 top-full mt-1 w-80 bg-surface-card border border-border-default rounded-lg shadow-lg z-50"
    >
        <div class="flex items-center justify-between px-3 py-2 border-b border-border-default">
            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Notifications') }}</span>
            @if ($unreadCount > 0)
                <button
                    type="button"
                    wire:click="markAllRead"
                    class="text-xs text-accent hover:underline"
                >
                    {{ __('Mark all read') }}
                </button>
            @endif
        </div>

        <ul class="max-h-96 overflow-y-auto py-1" aria-label="{{ __('Recent notifications') }}">
            @forelse ($items as $item)
                <li wire:key="notification-{{ $item['id'] }}">
                    <button
                        type="button"
                        wire:click="visit('{{ $item['id'] }}')"
                        @click="open = false"
                        class="w-full flex items-start gap-2 px-3 py-2 text-left hover:bg-surface-subtle transition-colors"
                    >
                        <span
                            class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full {{ $item['read'] ? 'bg-transparent' : 'bg-accent' }}"
                            aria-hidden="true"
                        ></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-xs {{ $item['read'] ? 'text-muted' : 'text-ink font-medium' }} truncate">{{ $item['title'] }}</span>
                            @if ($item['body'] !== '')
                                <span class="block text-xs text-muted line-clamp-2">{{ $item['body'] }}</span>
                            @endif
                            @if ($item['time'])
                                <x-ui.datetime :value="$item['time']" class="block mt-0.5 text-[10px] text-muted" />
                            @endif
                        </span>
                    </button>
                </li>
            @empty
                <li class="px-3 py-6 text-center text-xs text-muted">{{ __("You're all caught up.") }}</li>
            @endforelse
        </ul>
    </div>
</div>
