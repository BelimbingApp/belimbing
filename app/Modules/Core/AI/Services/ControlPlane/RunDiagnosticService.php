<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Base\DateTime\Contracts\DateTimeDisplayService;
use App\Base\Support\Json as BlbJson;
use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunCall;
use App\Modules\Core\AI\Values\CallUsage;
use App\Modules\Core\Employee\Models\Employee;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class RunDiagnosticService
{
    /** @var list<string> */
    private const KNOWN_ENTRY_TYPES = ['message', 'tool_use', 'thinking', 'hook_action'];

    public function __construct(
        private readonly WireLogger $wireLogger,
        private readonly WireLogReadableFormatter $wireLogFormatter,
        private readonly DateTimeDisplayService $dateTimeDisplay,
    ) {}

    public function inspectRun(string $runId): ?AiRun
    {
        return AiRun::query()
            ->with(['employee', 'actingForUser'])
            ->find($runId);
    }

    /**
     * @return array{
     *     inspection: RunInspection,
     *     transcript: list<Message>,
     *     triggering_prompt: Message|null,
     *     wire_log_entries: list<array{
     *         entry_number: int,
     *         at: string|null,
     *         type: string|null,
     *         payload_pretty: string,
     *         payload_truncated: bool,
     *         preview_status: string,
     *         raw_line: string,
     *         decoded_payload: array<string, mixed>|null
     *     }>,
     *     wire_log_readable: array<string, mixed>,
     *     wire_log_summary: array{
     *         footprint_bytes: int,
     *         total_entries: int,
     *         visible_entries: int,
     *         offset: int,
     *         limit: int,
     *         range_start: int,
     *         range_end: int,
     *         omitted_before: int,
     *         omitted_after: int,
     *         has_previous: bool,
     *         has_next: bool,
     *         last_offset: int
     *     },
     *     wire_logging_enabled: bool,
     *     run_id: string|null
     * }|null
     */
    public function buildRunView(string $runId, int $wireLogOffset = 0, int $wireLogLimit = 100): ?array
    {
        $run = $this->inspectRun($runId);

        if ($run === null) {
            return null;
        }

        $wireLogPreview = $this->wireLogger->preview($run->id, $wireLogOffset, $wireLogLimit);

        $readable = $this->wireLogFormatter->format($wireLogPreview['entries']);

        return [
            'inspection' => RunInspection::fromAiRun($run),
            'transcript' => $this->runTranscript($run),
            'triggering_prompt' => $this->triggeringPrompt($run),
            'wire_log_entries' => $wireLogPreview['entries'],
            'wire_log_readable' => $this->enrichReadableAttemptsWithCalls($readable, $run),
            'wire_log_summary' => [
                'footprint_bytes' => $wireLogPreview['footprint_bytes'],
                'total_entries' => $wireLogPreview['total_entries'],
                'visible_entries' => $wireLogPreview['visible_entries'],
                'offset' => $wireLogPreview['offset'],
                'limit' => $wireLogPreview['limit'],
                'range_start' => $wireLogPreview['range_start'],
                'range_end' => $wireLogPreview['range_end'],
                'omitted_before' => $wireLogPreview['omitted_before'],
                'omitted_after' => $wireLogPreview['omitted_after'],
                'has_previous' => $wireLogPreview['has_previous'],
                'has_next' => $wireLogPreview['has_next'],
                'last_offset' => $wireLogPreview['last_offset'],
            ],
            'wire_logging_enabled' => $this->wireLogger->enabled(),
            'run_id' => $run->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $readable
     * @return array<string, mixed>
     */
    private function enrichReadableAttemptsWithCalls(array $readable, AiRun $run): array
    {
        if (! is_array($readable['attempts'] ?? null) || $readable['attempts'] === []) {
            return $readable;
        }

        $run->loadMissing('calls');
        $callsByAttempt = $run->calls->keyBy('attempt_index');
        $callsByPosition = $run->calls->values();

        foreach ($readable['attempts'] as $position => $attempt) {
            if (! is_array($attempt)) {
                continue;
            }

            $attemptIndex = isset($attempt['index']) && is_numeric($attempt['index'])
                ? ((int) $attempt['index']) - 1
                : $position;

            /** @var AiRunCall|null $call */
            $call = $callsByAttempt->get($attemptIndex) ?? $callsByPosition->get($position);
            $readable['attempts'][$position]['usage_chip'] = $this->buildAttemptUsageChip($attempt, $call);
        }

        return $readable;
    }

    /**
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>|null
     */
    private function buildAttemptUsageChip(array $attempt, ?AiRunCall $call): ?array
    {
        if ($call !== null) {
            return [
                'call_id' => $call->id,
                'prompt_tokens' => $call->prompt_tokens,
                'cached_input_tokens' => $call->cached_input_tokens,
                'completion_tokens' => $call->completion_tokens,
                'reasoning_tokens' => $call->reasoning_tokens,
                'total_tokens' => $call->total_tokens,
                'finish_reason' => $call->finish_reason ?? $attempt['finish_reason'] ?? null,
                'cost_total_cents' => $call->cost_total_cents,
                'pricing_source' => $call->pricing_source,
            ];
        }

        $usage = $this->usageFromAttemptSections($attempt);

        if ($usage === null) {
            return null;
        }

        return [
            'call_id' => null,
            'prompt_tokens' => $usage->promptTokens,
            'cached_input_tokens' => $usage->cachedInputTokens,
            'completion_tokens' => $usage->completionTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'total_tokens' => $usage->totalTokens,
            'finish_reason' => $attempt['finish_reason'] ?? null,
            'cost_total_cents' => null,
            'pricing_source' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $attempt
     */
    private function usageFromAttemptSections(array $attempt): ?CallUsage
    {
        $usage = null;

        foreach ($attempt['sections'] ?? [] as $section) {
            if (! is_array($section) || ! is_array($section['usage'] ?? null)) {
                continue;
            }

            $usage = CallUsage::fromProviderArray($section['usage']);
        }

        return $usage;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentRuns(int $limit = 20): array
    {
        return $this->recentRunsQuery()
            ->limit($limit)
            ->get()
            ->map(fn (AiRun $run): array => $this->mapRecentRun($run))
            ->values()
            ->all();
    }

    public function recentRunsQuery(string $search = ''): Builder
    {
        $query = AiRun::query()
            ->with(['employee'])
            ->orderByDesc('started_at')
            ->orderByDesc('created_at');

        if ($search !== '') {
            $query->where('id', 'like', '%'.$search.'%');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function mapRecentRun(AiRun $run): array
    {
        $inspection = RunInspection::fromAiRun($run)->toArray();
        $wireLogFootprintBytes = $this->wireLogger->footprintBytes($run->id);

        return array_merge($inspection, [
            'employee_name' => $run->employee?->displayName() ?? __('Unknown Agent'),
            'run_id' => $run->id,
            'turn_id' => $run->source === 'chat' ? $run->id : null,
            'status_label' => $run->status?->label(),
            'status_color' => $run->status?->color(),
            'turn_status' => $run->source === 'chat' ? $run->status?->value : null,
            'turn_status_label' => $run->source === 'chat' ? $run->status?->label() : null,
            'turn_status_color' => $run->source === 'chat' ? $run->status?->color() : null,
            'wire_log_footprint_bytes' => $wireLogFootprintBytes,
            'wire_log_footprint_display' => Number::fileSize($wireLogFootprintBytes),
            'recorded_at_display' => $this->dateTimeDisplay->formatDateTime($inspection['recorded_at'] ?? null),
            'started_at_display' => $this->dateTimeDisplay->formatDateTime($inspection['started_at'] ?? null),
        ]);
    }

    public function wireLogDiskUsageBytes(): int
    {
        return $this->wireLogger->totalBytes();
    }

    /**
     * @return list<Message>
     */
    public function runTranscript(AiRun $run): array
    {
        $allMessages = $this->readTranscriptMessages($run);

        $entries = array_values(array_filter(
            $allMessages,
            fn (Message $message): bool => $message->runId === $run->id,
        ));

        $hasTypedEntries = array_filter(
            $entries,
            fn (Message $message): bool => in_array($message->type, ['thinking', 'tool_use'], true),
        ) !== [];

        if (! $hasTypedEntries) {
            $entries = array_merge($entries, $this->synthesizeFromToolActions($run));
        }

        return $entries;
    }

    public function triggeringPrompt(AiRun $run): ?Message
    {
        $messages = $this->readTranscriptMessages($run);

        if ($messages === []) {
            return null;
        }

        $cutoff = $run->started_at?->getTimestamp() ?? $run->created_at?->getTimestamp();
        $prompt = null;

        foreach ($messages as $message) {
            if ($message->role !== 'user') {
                continue;
            }

            if ($cutoff !== null && $message->timestamp->getTimestamp() > $cutoff) {
                continue;
            }

            $prompt = $message;
        }

        return $prompt;
    }

    /**
     * @return list<Message>
     */
    private function readTranscriptMessages(AiRun $run): array
    {
        $path = $this->transcriptPath($run);

        if ($path === null || ! file_exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $messages = [];

        foreach ($lines as $line) {
            $data = BlbJson::decodeArray($line);

            if ($data === null) {
                continue;
            }

            $type = $data['type'] ?? 'message';

            if (! in_array($type, self::KNOWN_ENTRY_TYPES, true)) {
                continue;
            }

            $message = Message::fromJsonLine($data);

            if ($message->role === 'assistant' && $message->runId === $run->id) {
                $message = new Message(
                    role: $message->role,
                    content: $message->content,
                    timestamp: $message->timestamp,
                    runId: $message->runId,
                    meta: array_merge($message->meta, $this->buildMessageMetaFromRun($run)),
                    type: $message->type,
                );
            }

            $messages[] = $message;
        }

        return $messages;
    }

    private function transcriptPath(AiRun $run): ?string
    {
        if (! is_string($run->session_id) || $run->session_id === '') {
            return null;
        }

        $base = rtrim((string) config('ai.workspace_path'), '/').'/'.$run->employee_id.'/sessions';

        if ($run->employee_id === Employee::LARA_ID) {
            if (! is_int($run->acting_for_user_id)) {
                return null;
            }

            $base .= '/'.$run->acting_for_user_id;
        }

        return $base.'/'.$run->session_id.'.jsonl';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMessageMetaFromRun(AiRun $run): array
    {
        return [
            'model' => $run->model,
            'provider_name' => $run->provider_name,
            'llm' => [
                'provider' => $run->provider_name ?? 'unknown',
                'model' => $run->model ?? 'unknown',
            ],
            'latency_ms' => $run->latency_ms,
            'tokens' => [
                'prompt' => $run->prompt_tokens,
                'completion' => $run->completion_tokens,
            ],
            'timeout_seconds' => $run->timeout_seconds,
            'status' => $run->status?->value,
            'error_type' => $run->error_type,
            'error_message' => $run->error_message,
        ];
    }

    /**
     * @return list<Message>
     */
    private function synthesizeFromToolActions(AiRun $run): array
    {
        $actions = $run->tool_actions ?? [];

        if ($actions === []) {
            return [];
        }

        $timestamp = $run->started_at ?? $run->created_at ?? now();
        $messages = [];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $messages[] = new Message(
                role: 'assistant',
                content: '',
                timestamp: new DateTimeImmutable($timestamp->toIso8601String()),
                runId: $run->id,
                meta: [
                    'tool' => (string) ($action['tool'] ?? $action['name'] ?? 'unknown'),
                    'args_summary' => $this->buildArgsSummary($action),
                    'status' => isset($action['error_payload']) ? 'error' : 'success',
                    'result_preview' => (string) ($action['result_preview'] ?? ''),
                    'result_length' => isset($action['result_length']) ? (int) $action['result_length'] : 0,
                    'error_payload' => is_array($action['error_payload'] ?? null) ? $action['error_payload'] : null,
                    'synthesized' => true,
                ],
                type: 'tool_use',
            );
        }

        return $messages;
    }

    private function buildArgsSummary(array $action): string
    {
        if (isset($action['args_summary'])) {
            return (string) $action['args_summary'];
        }

        if (isset($action['arguments']) && is_array($action['arguments'])) {
            return Str::limit(
                json_encode($action['arguments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                200,
            );
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function mapTurn(AiRun $turn): array
    {
        $terminalEvent = $turn->events()
            ->whereIn('event_type', ['run.completed', 'run.failed', 'run.cancelled'])
            ->latest('seq')
            ->first();

        $cancelReason = is_array($terminalEvent?->payload ?? null)
            ? (string) ($terminalEvent->payload['reason'] ?? '')
            : '';

        $cancelMode = match (true) {
            $turn->cancel_requested_at === null => null,
            str_contains($cancelReason, 'stale') => 'force_stopped',
            $terminalEvent?->event_type?->value === 'run.cancelled' => 'cooperative_cancel',
            default => 'cancel_requested',
        };

        return [
            'id' => $turn->id,
            'employee_id' => $turn->employee_id,
            'employee_name' => $turn->employee?->displayName() ?? __('Unknown Agent'),
            'session_id' => $turn->session_id,
            'acting_for_user_id' => $turn->acting_for_user_id,
            'status' => $turn->status->value,
            'status_label' => $turn->status->label(),
            'status_color' => $turn->status->color(),
            'current_phase' => $turn->current_phase?->value,
            'current_phase_label' => $turn->current_phase?->label(),
            'current_label' => $turn->current_label,
            'last_event_seq' => $turn->last_event_seq,
            'current_run_id' => $turn->id,
            'started_at' => $turn->started_at?->toIso8601String(),
            'finished_at' => $turn->finished_at?->toIso8601String(),
            'created_at' => $turn->created_at?->toIso8601String(),
            'cancel_requested_at' => $turn->cancel_requested_at?->toIso8601String(),
            'cancel_mode' => $cancelMode,
            'cancel_mode_label' => match ($cancelMode) {
                'force_stopped' => __('Force-stopped'),
                'cooperative_cancel' => __('Cooperative cancel'),
                'cancel_requested' => __('Cancel requested'),
                default => null,
            },
            'cancel_terminal_at' => $terminalEvent?->created_at?->toIso8601String(),
            'event_count' => $turn->events()->count(),
        ];
    }

    /**
     * @return array{
     *     run: array<string, mixed>,
     *     timeline: list<array<string, mixed>>,
     *     wire_count: int,
     *     meta_count: int,
     *     delta_collapsed: bool,
     *     has_wire_log: bool,
     * }|null
     */
    public function buildPromptTimelineView(string $runId, bool $collapseDelta = false): ?array
    {
        $run = AiRun::query()
            ->with(['employee', 'events' => fn ($query) => $query->orderBy('seq')])
            ->find($runId);

        if ($run === null) {
            return null;
        }

        $composed = (new RunPromptTimelineBuilder($this->wireLogger))->compose($run, $collapseDelta);

        return [
            'run' => $this->mapTurn($run),
            ...$composed,
        ];
    }
}
