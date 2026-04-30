<?php
// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

/** @var list<\App\Modules\Core\AI\DTO\Message> $transcript */
?>
<div class="space-y-4">
    @if ($transcript !== [])
        <div class="space-y-2">
            @php
                $markdown = app(\App\Modules\Core\AI\Services\ChatMarkdownRenderer::class);
            @endphp

            @foreach ($transcript as $message)
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
    @else
        <x-ui.alert variant="info">
            {{ __('No transcript is available for this run. The run may not have an associated session, or the transcript may already have been pruned.') }}
        </x-ui.alert>
    @endif
</div>
