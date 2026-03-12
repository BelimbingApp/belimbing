<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
?>

@props([
    'canAttachFiles' => false,
    'attachments' => [],
    'attachmentsModel' => 'attachments',
    'removeAttachmentAction' => 'removeAttachment',
    'messageModel' => 'messageInput',
    'placeholder' => __('Type a message...'),
    'composerRef' => 'agentComposer',
    'pendingExpression' => 'false',
])

<div class="space-y-2">
    {{-- Attachment preview pills --}}
    @if ($canAttachFiles && count($attachments) > 0)
        <div class="flex flex-wrap gap-1.5">
            @foreach ($attachments as $index => $file)
                <div class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-surface-subtle border border-border-default text-xs text-ink" wire:key="att-{{ $index }}">
                    @if ($file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && str_starts_with($file->getMimeType() ?? '', 'image/'))
                        <x-icon name="heroicon-o-photo" class="w-3.5 h-3.5 text-muted shrink-0" />
                    @else
                        <x-icon name="heroicon-o-document" class="w-3.5 h-3.5 text-muted shrink-0" />
                    @endif
                    <span class="truncate max-w-32">{{ $file instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile ? $file->getClientOriginalName() : __('File') }}</span>
                    <button
                        type="button"
                        wire:click="{{ $removeAttachmentAction }}({{ $index }})"
                        class="text-muted hover:text-ink transition-colors shrink-0"
                        aria-label="{{ __('Remove attachment') }}"
                    >
                        <x-icon name="heroicon-o-x-mark" class="w-3 h-3" />
                    </button>
                </div>
            @endforeach
        </div>
    @endif

    <div class="flex items-center gap-2">
        @if ($canAttachFiles)
            <label class="shrink-0 cursor-pointer text-muted hover:text-ink transition-colors p-1" title="{{ __('Attach file') }}" aria-label="{{ __('Attach file') }}">
                <x-icon name="heroicon-o-arrow-down-tray" class="w-4 h-4" />
                <input
                    x-ref="attachmentInput"
                    type="file"
                    wire:model="{{ $attachmentsModel }}"
                    multiple
                    accept="image/*,.pdf,.txt,.csv,.md,.json"
                    class="hidden"
                />
            </label>
        @endif
        <div class="flex-1 min-w-0">
            <textarea
                x-ref="{{ $composerRef }}"
                wire:model="{{ $messageModel }}"
                x-on:keydown="window.sharedChatComposerOnKeydown($event, $el)"
                x-on:paste="window.sharedChatComposerPasteFiles($event, $refs.attachmentInput, $wire, '{{ $attachmentsModel }}')"
                x-on:input="window.sharedChatComposerAutoResize($el)"
                x-init="window.sharedChatComposerAutoResize($el)"
                placeholder="{{ $placeholder }}"
                autocomplete="off"
                x-bind:disabled="{!! $pendingExpression !!}"
                rows="1"
                class="w-full min-h-9 px-input-x py-input-y text-sm border rounded-2xl transition-colors border-border-input bg-surface-card text-ink placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent disabled:opacity-50 disabled:cursor-not-allowed resize-none overflow-hidden"
            ></textarea>
        </div>
        <x-ui.button type="submit" variant="primary" x-bind:disabled="{!! $pendingExpression !!}" class="shrink-0">
            <x-icon name="heroicon-o-paper-airplane" class="w-4 h-4" />
        </x-ui.button>
    </div>
</div>

@once
    @script
    <script>
        if (! window.sharedChatComposerAutoResize) {
            window.sharedChatComposerAutoResize = (el) => {
                el.style.height = 'auto';
                const lineHeight = parseInt(getComputedStyle(el).lineHeight) || 20;
                const maxHeight = lineHeight * 6;
                const minHeight = 36;
                const newHeight = Math.max(minHeight, Math.min(el.scrollHeight, maxHeight));
                el.style.height = newHeight + 'px';
                el.style.overflowY = el.scrollHeight > maxHeight ? 'auto' : 'hidden';
            };
        }

        if (! window.sharedChatComposerOnKeydown) {
            window.sharedChatComposerOnKeydown = (event, el) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    el.closest('form')?.requestSubmit();
                }
            };
        }

        if (! window.sharedChatComposerPasteFiles) {
            window.sharedChatComposerPasteFiles = (event, attachmentInput, wire, attachmentsModel) => {
                const items = Array.from(event.clipboardData?.items ?? []);
                const files = items
                    .filter((item) => item.kind === 'file')
                    .map((item) => item.getAsFile())
                    .filter((file) => file instanceof File);

                if (files.length === 0) {
                    return;
                }

                event.preventDefault();

                if (typeof wire?.uploadMultiple === 'function') {
                    wire.uploadMultiple(
                        attachmentsModel,
                        files,
                        () => {
                            if (attachmentInput) {
                                attachmentInput.value = '';
                            }
                        },
                        () => {}
                    );

                    return;
                }

                if (!attachmentInput || typeof DataTransfer === 'undefined') {
                    return;
                }

                const transfer = new DataTransfer();
                Array.from(attachmentInput.files ?? []).forEach((file) => transfer.items.add(file));
                files.forEach((file) => transfer.items.add(file));
                attachmentInput.files = transfer.files;
                attachmentInput.dispatchEvent(new Event('change', { bubbles: true }));
            };
        }
    </script>
    @endscript
@endonce
