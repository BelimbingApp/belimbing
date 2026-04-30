<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var list<\App\Modules\Core\AI\DTO\Message> $transcript */
/** @var \App\Modules\Core\AI\DTO\Message|null $triggeringPrompt */
?>
<x-ui.card id="activity-transcript-card">
    <x-ui.disclosure
        :title="__('Activity Transcript')"
        variant="card-header"
        :default-open="true"
        panel-id="activity-transcript-panel"
        content-class="space-y-3"
    >
        <x-slot name="hint">
            <p class="text-xs text-muted">
                {{ __('Thinking, tool use, and assistant output are replayed from the persisted transcript for this run.') }}
            </p>
        </x-slot>

        @if ($triggeringPrompt)
            <div
                class="sticky top-0 z-20 -mx-card-inner cursor-pointer rounded-2xl border border-border-default bg-surface-subtle/95 px-card-inner py-card-inner shadow-sm backdrop-blur"
                @click="document.getElementById('activity-transcript-card')?.scrollIntoView({ block: 'start' })"
            >
                <x-ai.activity.user-message :content="$triggeringPrompt->content" :timestamp="$triggeringPrompt->timestamp" />
            </div>
        @endif

        @include('livewire.admin.ai.control-plane.partials.activity-transcript', [
            'transcript' => $transcript,
        ])
    </x-ui.disclosure>
</x-ui.card>
