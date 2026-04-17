<?php

use App\Modules\Core\AI\Livewire\RunDetail;
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var RunDetail $this */
/** @var array<string, mixed>|null $inspection */
/** @var list<\App\Modules\Core\AI\DTO\Message> $transcript */
?>
<div>
    <x-slot name="title">{{ __('Run :id', ['id' => \Illuminate\Support\Str::limit($runId, 8, '…')]) }}</x-slot>

    <x-ui.page-header :title="__('Run Detail')">
        <x-slot name="actions">
            @if($inspection['session_id'] ?? null)
                <a
                    href="{{ route('admin.ai.control-plane') }}"
                    wire:navigate
                    class="text-sm text-accent hover:underline"
                >
                    ← {{ __('Control Plane') }}
                </a>
            @endif
        </x-slot>
    </x-ui.page-header>

    @if($inspection)
        <div class="space-y-6">
            {{-- Run metadata --}}
            <x-ui.card>
                @include('livewire.admin.ai.control-plane.partials.run-detail', ['run' => $inspection])
            </x-ui.card>

            {{-- Activity transcript --}}
            @if(count($transcript) > 0)
                <x-ui.card>
                    <div class="mb-3">
                        <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Activity Transcript') }}</span>
                    </div>
                    <div class="space-y-2">
                        @php
                            $markdown = app(\App\Modules\Core\AI\Services\ChatMarkdownRenderer::class);
                        @endphp
                        @foreach($transcript as $message)
                            @if ($message->type === 'thinking')
                                <x-ai.activity.thinking :timestamp="$message->timestamp" :active="false" :content="$message->content" />
                            @elseif ($message->type === 'tool_use')
                                <x-ai.activity.tool-use
                                    :tool="$message->meta['tool'] ?? ''"
                                    :args-summary="$message->meta['args_summary'] ?? '{}'"
                                    :status="$message->meta['status'] ?? 'success'"
                                    :duration-ms="$message->meta['duration_ms'] ?? null"
                                    :result-preview="$message->meta['result_preview'] ?? ''"
                                    :result-length="$message->meta['result_length'] ?? 0"
                                    :error-payload="$message->meta['error_payload'] ?? null"
                                />
                            @elseif ($message->type === 'hook_action')
                                <x-ai.activity.hook-action
                                    :stage="$message->meta['stage'] ?? 'unknown'"
                                    :action="$message->meta['action'] ?? 'unknown'"
                                    :tool="$message->meta['tool'] ?? null"
                                    :tools-removed="$message->meta['tools_removed'] ?? []"
                                    :reason="$message->meta['reason'] ?? null"
                                    :source="$message->meta['source'] ?? null"
                                    :timestamp="$message->timestamp"
                                />
                            @elseif ($message->role === 'user')
                                <x-ai.activity.user-message :content="$message->content" :timestamp="$message->timestamp" />
                            @elseif ($message->role === 'assistant' && ($message->meta['message_type'] ?? null) === 'error')
                                <x-ai.activity.error
                                    :message="$message->content"
                                    :error-type="$message->meta['error_type'] ?? null"
                                    :timestamp="$message->timestamp"
                                    :markdown="$markdown"
                                />
                            @elseif ($message->role === 'assistant')
                                <x-ai.activity.assistant-result
                                    :content="$message->content"
                                    :timestamp="$message->timestamp"
                                    :markdown="$markdown"
                                />
                            @endif
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        </div>
    @else
        <x-ui.alert variant="warning">
            {{ __('Run not found or inspection data unavailable.') }}
        </x-ui.alert>
    @endif
</div>
