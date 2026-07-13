<?php

use App\Modules\Core\AI\Livewire\RunDetail;
use Illuminate\Support\Str;

/** @var RunDetail $this */
/** @var array<string, mixed> $runView */
/** @var array{label: string, url: string|null}|null $operationsBreadcrumb */
?>
<div>
    <x-slot name="title">{{ __('Run :id', ['id' => Str::limit($runId, 8, '...')]) }}</x-slot>

    <x-ui.page-header :title="__('Run Detail')" :subtitle="__('Standalone deep link for a single AI run.')">
        <x-slot name="actions">
            <x-ui.link-group>
                <x-ui.link kind="anchor" href="#wire-log-panel">
                    {{ __('Wire Log') }}
                </x-ui.link>
                @if ($operationsBreadcrumb && $operationsBreadcrumb['url'])
                    <x-ui.link href="{{ $operationsBreadcrumb['url'] }}">
                        {{ __('Back to :label', ['label' => $operationsBreadcrumb['label']]) }}
                    </x-ui.link>
                @endif
                <x-ui.link href="{{ route('admin.ai.control-plane', array_merge(request()->only(['from', 'returnTo']), ['tab' => 'inspector', 'runId' => $runId])) }}">
                    {{ __('Back to Control Plane') }}
                </x-ui.link>
            </x-ui.link-group>
        </x-slot>
    </x-ui.page-header>

    <div class="space-y-section-gap">
        <x-ui.card>
            @include('livewire.admin.ai.control-plane.partials.run-detail', ['run' => $runView['inspection']])
        </x-ui.card>

        @include('livewire.admin.ai.control-plane.partials.activity-transcript-card', [
            'transcript' => $runView['transcript'],
            'triggeringPrompt' => $runView['triggering_prompt'],
        ])

        <x-ui.card id="wire-log-panel">
            <h3 class="mb-4 text-sm font-medium text-ink">{{ __('Wire Log') }}</h3>
            @include('livewire.admin.ai.control-plane.partials.wire-log', [
                'entries' => $runView['wire_log_entries'],
                'readable' => $runView['wire_log_readable'],
                'summary' => $runView['wire_log_summary'],
                'wireLoggingEnabled' => $runView['wire_logging_enabled'],
                'runId' => $runId,
            ])
        </x-ui.card>
    </div>
</div>
