<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var list<\App\Modules\Core\AI\DTO\Message> $transcript */
/** @var \App\Modules\Core\AI\DTO\Message|null $triggeringPrompt */
?>
<x-ui.card>
    <div x-data="{ activityTranscriptOpen: true }">
        <div class="mb-4 flex items-center justify-between gap-3">
            <div>
                <button
                    type="button"
                    @click="activityTranscriptOpen = ! activityTranscriptOpen"
                    class="group inline-flex items-center gap-2 rounded-2xl text-left text-sm font-medium text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                    aria-controls="activity-transcript-panel"
                    x-bind:aria-expanded="activityTranscriptOpen.toString()"
                >
                    <span>{{ __('Activity Transcript') }}</span>
                    <x-icon
                        name="heroicon-o-chevron-down"
                        class="h-4 w-4 text-muted transition-transform duration-200 motion-reduce:transition-none"
                        x-bind:class="activityTranscriptOpen ? 'rotate-180' : 'rotate-0'"
                    />
                </button>
                <p x-show="activityTranscriptOpen" x-cloak class="mt-1 text-xs text-muted">
                    {{ __('Thinking, tool use, and assistant output are replayed from the persisted transcript for this run.') }}
                </p>
            </div>
        </div>

        <div id="activity-transcript-panel" x-show="activityTranscriptOpen" x-cloak>
            @include('livewire.admin.ai.control-plane.partials.activity-transcript', [
                'transcript' => $transcript,
                'triggeringPrompt' => $triggeringPrompt,
            ])
        </div>
    </div>
</x-ui.card>
