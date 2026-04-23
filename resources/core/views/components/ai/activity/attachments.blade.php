<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    /** @var list<array{id: string, kind: string, mime_type: string, original_name: string, size: int|null, url: string}> */
    'attachments',
])

@php
    $visibleLimit = 5;
    $visible = array_slice($attachments, 0, $visibleLimit);
    $hiddenCount = max(0, count($attachments) - $visibleLimit);
@endphp

<div
    class="flex flex-wrap justify-end gap-2"
    x-data="{
        showAll: false,
        imageOpen: false,
        imageIndex: 0,
        fileOpen: false,
        file: null,
        attachments: @js($attachments),
        visibleLimit: {{ $visibleLimit }},
        get shown() {
            return this.showAll ? this.attachments : this.attachments.slice(0, this.visibleLimit);
        },
        openImage(i) {
            this.imageIndex = i;
            this.imageOpen = true;
        },
        nextImage() {
            const images = this.attachments.filter(a => a.kind === 'image');
            if (images.length === 0) return;
            this.imageIndex = (this.imageIndex + 1) % images.length;
        },
        prevImage() {
            const images = this.attachments.filter(a => a.kind === 'image');
            if (images.length === 0) return;
            this.imageIndex = (this.imageIndex - 1 + images.length) % images.length;
        },
        openFile(att) {
            this.file = att;
            this.fileOpen = true;
        },
        humanSize(bytes) {
            const n = Number(bytes || 0);
            if (!n) return null;
            if (n < 1024) return n + ' B';
            if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
            if (n < 1024 * 1024 * 1024) return (n / (1024 * 1024)).toFixed(1) + ' MB';
            return (n / (1024 * 1024 * 1024)).toFixed(1) + ' GB';
        }
    }"
>
    <template x-for="(att, idx) in shown" :key="att.id">
        <div>
            <template x-if="att.kind === 'image'">
                <button
                    type="button"
                    class="group relative w-6 h-6 rounded-md overflow-hidden border border-white/20 bg-white/10"
                    x-on:click="openImage(attachments.filter(a => a.kind === 'image').findIndex(a => a.id === att.id))"
                    :title="att.original_name"
                    :aria-label="'Open image attachment: ' + att.original_name"
                >
                    <img :src="att.url" :alt="att.original_name" class="w-full h-full object-cover opacity-95 group-hover:opacity-100" loading="lazy" />
                </button>
            </template>

            <template x-if="att.kind !== 'image'">
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-white/20 bg-white/10 px-2 py-1 text-[11px] text-accent-on/95 hover:bg-white/15 max-w-56"
                    x-on:click="openFile(att)"
                    :title="att.original_name"
                    :aria-label="'Open file attachment: ' + att.original_name"
                >
                    <span class="shrink-0 opacity-90">
                        <x-icon name="heroicon-o-paperclip" class="w-3.5 h-3.5" />
                    </span>
                    <span class="truncate" x-text="att.original_name"></span>
                </button>
            </template>
        </div>
    </template>

    @if ($hiddenCount > 0)
        <button
            type="button"
            class="inline-flex items-center rounded-lg border border-white/20 bg-white/10 px-2 py-1 text-[11px] text-accent-on/90 hover:bg-white/15"
            x-on:click="showAll = !showAll"
            x-text="showAll ? @js(__('Show less')) : @js('+'.$hiddenCount)"
        ></button>
    @endif

    {{-- Image lightbox --}}
    <div
        x-show="imageOpen"
        x-cloak
        x-on:keydown.escape.window="imageOpen = false"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
    >
        <div class="relative w-full max-w-3xl">
            <button
                type="button"
                class="absolute -top-3 -right-3 rounded-full bg-black/70 text-white p-2 hover:bg-black/80"
                x-on:click="imageOpen = false"
                aria-label="{{ __('Close') }}"
            >
                <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
            </button>

            <template x-if="attachments.filter(a => a.kind === 'image').length > 0">
                <div class="rounded-xl overflow-hidden border border-white/10 bg-black/30">
                    <img
                        :src="attachments.filter(a => a.kind === 'image')[imageIndex]?.url"
                        :alt="(attachments.filter(a => a.kind === 'image')[imageIndex]?.original_name) || @js(__('Preview'))"
                        class="w-full max-h-[75vh] object-contain bg-black"
                    />
                    <div class="flex items-center justify-between gap-2 p-3 text-xs text-white/80">
                        <div class="truncate" x-text="attachments.filter(a => a.kind === 'image')[imageIndex]?.original_name"></div>
                        <div class="flex items-center gap-2 shrink-0">
                            <a
                                class="underline hover:text-white"
                                :href="attachments.filter(a => a.kind === 'image')[imageIndex]?.url"
                                target="_blank"
                                rel="noreferrer"
                            >{{ __('Open') }}</a>
                            <button type="button" class="px-2 py-1 rounded bg-white/10 hover:bg-white/15" x-on:click="prevImage()">{{ __('Prev') }}</button>
                            <button type="button" class="px-2 py-1 rounded bg-white/10 hover:bg-white/15" x-on:click="nextImage()">{{ __('Next') }}</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    {{-- File details modal --}}
    <div
        x-show="fileOpen"
        x-cloak
        x-on:keydown.escape.window="fileOpen = false"
        class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/70 p-0 sm:p-4"
    >
        <div class="w-full sm:max-w-lg rounded-t-2xl sm:rounded-2xl bg-surface-card border border-border-default p-4">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="text-sm font-semibold text-ink wrap-break-word" x-text="file?.original_name"></div>
                    <div class="mt-1 text-xs text-muted">
                        <span x-text="file?.mime_type"></span>
                        <template x-if="file?.size">
                            <span x-text="' · ' + humanSize(file.size)"></span>
                        </template>
                    </div>
                </div>
                <button type="button" class="text-muted hover:text-ink" x-on:click="fileOpen = false" aria-label="{{ __('Close') }}">
                    <x-icon name="heroicon-o-x-mark" class="w-5 h-5" />
                </button>
            </div>

            <div class="mt-4 flex gap-2 justify-end">
                <a
                    class="inline-flex items-center gap-2 rounded-lg bg-accent px-3 py-2 text-sm text-accent-on hover:brightness-105"
                    :href="file?.url"
                    target="_blank"
                    rel="noreferrer"
                >
                    <x-icon name="heroicon-o-arrow-top-right-on-square" class="w-4 h-4" />
                    <span>{{ __('Open') }}</span>
                </a>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-border-default bg-surface-subtle px-3 py-2 text-sm text-ink hover:bg-surface-card"
                    x-on:click="fileOpen = false"
                >{{ __('Close') }}</button>
            </div>
        </div>
    </div>
</div>
