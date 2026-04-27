<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var array<string, mixed> $readable */
/** @var string $runId */
?>
<div class="space-y-4">
    @if (! ($readable['has_entries'] ?? false))
        <x-ui.alert variant="info">
            {{ __('No retained wire-log entries to interpret.') }}
        </x-ui.alert>
    @else
        @php($attempts = $readable['attempts'] ?? [])
        @php($showAttemptHeader = count($attempts) > 1)

        <div class="space-y-section-gap">
            @foreach ($attempts as $attempt)
                @include('livewire.admin.ai.control-plane.partials.wire-log-readable.attempt', [
                    'attempt' => $attempt,
                    'runId' => $runId,
                    'showAttemptHeader' => $showAttemptHeader,
                ])
            @endforeach
        </div>
    @endif
</div>
