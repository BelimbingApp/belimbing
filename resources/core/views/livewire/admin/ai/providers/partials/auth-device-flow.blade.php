<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var array $deviceFlow Device flow state: status, user_code, verification_uri, error */
?>
@if($deviceFlow['status'] === 'pending')
    <div wire:poll.5s="pollDeviceFlow">
        <div
            class="bg-surface-subtle rounded-lg p-4 space-y-3"
            x-data="{ copied: false }"
        >
            <div class="space-y-2">
                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted block">{{ __('Step 1 — Copy your authorization code') }}</span>
                <div class="flex items-center gap-3">
                    <p class="text-2xl font-mono font-bold text-ink tracking-[0.3em] select-all">{{ $deviceFlow['user_code'] }}</p>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-accent bg-surface-card border border-border-default rounded-md hover:bg-surface-subtle transition-colors focus:ring-2 focus:ring-accent focus:ring-offset-2"
                        x-on:click="navigator.clipboard.writeText('{{ $deviceFlow['user_code'] }}').then(() => { copied = true; setTimeout(() => copied = false, 2000) }).catch(() => {})"
                        x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy') }}'"
                        :aria-label="copied ? '{{ __('Code copied to clipboard') }}' : '{{ __('Copy authorization code') }}'"
                    >
                    </button>
                </div>
            </div>

            <div class="space-y-1.5">
                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted block">{{ __('Step 2 — Paste it on GitHub') }}</span>
                <p class="text-xs text-muted">{{ __('Open the link below, paste the code, and approve access for Belimbing.') }}</p>
                <a
                    href="{{ $deviceFlow['verification_uri'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-sm text-accent hover:underline inline-flex items-center gap-1"
                >
                    {{ $deviceFlow['verification_uri'] }}
                    <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-3.5 h-3.5" />
                </a>
            </div>

            <div class="flex items-center gap-2 pt-1 border-t border-border-default">
                <div class="animate-spin h-3.5 w-3.5 border-2 border-accent border-t-transparent rounded-full"></div>
                <span class="text-xs text-muted">{{ __('Listening for approval — this will update automatically once you authorize on GitHub.') }}</span>
            </div>
        </div>
    </div>
@elseif($deviceFlow['status'] === 'idle')
    <div class="space-y-3">
        <p class="text-xs text-muted">{{ __('Connecting to GitHub Copilot requires that you authorize this application on GitHub.') }}</p>
        <x-ui.button variant="primary" wire:click="startDeviceFlow">
            <x-icon name="github" class="w-4 h-4" />
            {{ __('Start GitHub Login') }}
        </x-ui.button>
    </div>
@elseif($deviceFlow['status'] === 'success')
    <div class="flex items-center gap-2">
        <x-icon name="heroicon-o-check-circle" class="w-5 h-5 text-status-success" />
        <span class="text-sm font-medium text-ink">{{ __('GitHub Copilot authorized — connecting…') }}</span>
    </div>
@else
    {{-- error / expired / denied --}}
    <div class="space-y-3">
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3">
            <p class="text-sm text-red-700 dark:text-red-400">{{ $deviceFlow['error'] ?? __('Authorization failed') }}</p>
        </div>
        <x-ui.button variant="ghost" wire:click="startDeviceFlow">
            <x-icon name="heroicon-o-arrow-path" class="w-4 h-4" />
            {{ __('Try Again') }}
        </x-ui.button>
    </div>
@endif
