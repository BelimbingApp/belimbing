<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Base\Support\Json as BlbJson;
use App\Base\Support\Str as BlbStr;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Models\AiRun;
use DateTimeImmutable;

class MessageManager
{
    /** @var list<string> Valid transcript entry types for v2 format */
    private const KNOWN_ENTRY_TYPES = ['message', 'tool_call', 'tool_result', 'thinking'];

    public function __construct(
        private readonly SessionManager $sessionManager,
    ) {}

    /**
     * Append a message to a session transcript.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @param  Message  $message  Message to append
     */
    public function append(int $employeeId, string $sessionId, Message $message): void
    {
        $path = $this->sessionManager->transcriptPath($employeeId, $sessionId);

        file_put_contents(
            $path,
            $message->toJsonLine()."\n",
            FILE_APPEND | LOCK_EX,
        );

        $this->sessionManager->touch($employeeId, $sessionId);
    }

    /**
     * Append a user message to a session transcript.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @param  string  $content  Message content
     * @param  array<string, mixed>  $meta  Optional metadata (e.g., attachment references)
     */
    public function appendUserMessage(int $employeeId, string $sessionId, string $content, array $meta = []): Message
    {
        $message = new Message(
            role: 'user',
            content: $content,
            timestamp: new DateTimeImmutable,
            meta: $meta,
        );

        $this->append($employeeId, $sessionId, $message);

        return $message;
    }

    /**
     * Append an assistant message to a session transcript.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @param  string  $content  Message content
     * @param  string|null  $runId  Runtime run ID
     * @param  array<string, mixed>  $meta  Runtime metadata (provider, model, latency, tokens)
     */
    public function appendAssistantMessage(
        int $employeeId,
        string $sessionId,
        string $content,
        ?string $runId = null,
        array $meta = [],
    ): Message {
        $timestamp = new DateTimeImmutable;

        $persistedMessage = new Message(
            role: 'assistant',
            content: $content,
            timestamp: $timestamp,
            runId: $runId,
            meta: $this->extractTranscriptMeta($meta),
        );

        $this->append($employeeId, $sessionId, $persistedMessage);

        return new Message(
            role: 'assistant',
            content: $content,
            timestamp: $timestamp,
            runId: $runId,
            meta: $meta,
        );
    }

    /**
     * Append a thinking indicator entry to a session transcript.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @param  string  $runId  Runtime run ID
     */
    public function appendThinking(int $employeeId, string $sessionId, string $runId): void
    {
        $this->append($employeeId, $sessionId, new Message(
            role: 'assistant',
            content: '',
            timestamp: new DateTimeImmutable,
            runId: $runId,
            type: 'thinking',
        ));
    }

    /**
     * Append a tool call entry to a session transcript.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @param  string  $runId  Runtime run ID
     * @param  string  $toolName  Tool name
     * @param  string  $argsSummary  Truncated args (≤200 chars)
     * @param  int  $toolCallIndex  Sequential index within the run
     */
    public function appendToolCall(
        int $employeeId,
        string $sessionId,
        string $runId,
        string $toolName,
        string $argsSummary,
        int $toolCallIndex,
    ): void {
        $this->append($employeeId, $sessionId, new Message(
            role: 'assistant',
            content: '',
            timestamp: new DateTimeImmutable,
            runId: $runId,
            meta: [
                'tool' => $toolName,
                'args_summary' => $argsSummary,
                'tool_call_index' => $toolCallIndex,
            ],
            type: 'tool_call',
        ));
    }

    /**
     * Append a tool result entry to a session transcript.
     *
     * Full result content is NOT persisted — only a truncated preview
     * and the result length. See Phase 0 §0.8 redaction rules.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @param  string  $runId  Runtime run ID
     * @param  string  $toolName  Tool name
     * @param  string  $resultPreview  Truncated preview (≤200 chars)
     * @param  int  $resultLength  Full result string length
     * @param  string  $status  'success' or 'error'
     * @param  int  $durationMs  Tool execution duration in milliseconds
     * @param  array<string, mixed>|null  $errorPayload  Error details when status is 'error'
     */
    public function appendToolResult(
        int $employeeId,
        string $sessionId,
        string $runId,
        string $toolName,
        string $resultPreview,
        int $resultLength,
        string $status,
        int $durationMs,
        ?array $errorPayload = null,
    ): void {
        $meta = [
            'tool' => $toolName,
            'result_preview' => $resultPreview,
            'result_length' => $resultLength,
            'status' => $status,
            'duration_ms' => $durationMs,
        ];

        if ($errorPayload !== null) {
            $meta['error_payload'] = $errorPayload;
        }

        $this->append($employeeId, $sessionId, new Message(
            role: 'assistant',
            content: '',
            timestamp: new DateTimeImmutable,
            runId: $runId,
            meta: $meta,
            type: 'tool_result',
        ));
    }

    /**
     * Search across all session transcripts for messages matching a query.
     *
     * Returns sessions that contain at least one message matching the query,
     * with a snippet from the first matching message.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $query  Search query (case-insensitive substring match)
     * @return list<array{session_id: string, title: string|null, snippet: string, matched_at: DateTimeImmutable}>
     */
    public function searchSessions(int $employeeId, string $query): array
    {
        $sessions = $this->sessionManager->list($employeeId);
        $results = [];

        foreach ($sessions as $session) {
            $path = $this->sessionManager->transcriptPath($employeeId, $session->id);

            $match = $this->findFirstMatchInFile($path, $query);

            if ($match === null) {
                continue;
            }

            $snippet = $this->extractSnippet($match['content'], $match['matchPos']);
            $timestamp = $match['timestamp'] !== null
                ? new DateTimeImmutable($match['timestamp'])
                : $session->lastActivityAt;

            $results[] = [
                'session_id' => $session->id,
                'title' => $session->title,
                'snippet' => $snippet,
                'matched_at' => $timestamp,
            ];
        }

        usort($results, fn (array $a, array $b) => $b['matched_at'] <=> $a['matched_at']);

        return $results;
    }

    /**
     * Find the first message in a transcript file that matches the query.
     *
     * @return array{content: string, matchPos: int, timestamp: string|null}|null
     */
    private function findFirstMatchInFile(string $path, string $query): ?array
    {
        if (! file_exists($path)) {
            return null;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            $data = BlbJson::decodeArray($line);

            if ($data === null) {
                continue;
            }

            $content = $data['content'] ?? null;

            if (! is_string($content)) {
                continue;
            }

            $matchPos = mb_stripos($content, $query);

            if ($matchPos === false) {
                continue;
            }

            return ['content' => $content, 'matchPos' => $matchPos, 'timestamp' => $data['timestamp'] ?? null];
        }

        return null;
    }

    /**
     * Extract a snippet of ~120 characters centered around the match position.
     */
    private function extractSnippet(string $content, int $matchPos): string
    {
        return BlbStr::snippetAround($content, $matchPos);
    }

    /**
     * Read all messages from a session transcript in order.
     *
     * Version-aware: v1 lines (no `type` field) default to 'message'.
     * Unknown `type` values are skipped gracefully — never crashes.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @return list<Message>
     */
    public function read(int $employeeId, string $sessionId): array
    {
        $path = $this->sessionManager->transcriptPath($employeeId, $sessionId);

        if (! file_exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $messages = [];
        $runIds = [];

        foreach ($lines as $line) {
            $data = BlbJson::decodeArray($line);

            if ($data === null) {
                continue;
            }

            $type = $data['type'] ?? 'message';

            if (! in_array($type, self::KNOWN_ENTRY_TYPES, true)) {
                continue;
            }

            $messages[] = Message::fromJsonLine($data);

            if ($type === 'message' && ($data['role'] ?? '') === 'assistant' && isset($data['run_id'])) {
                $runIds[] = $data['run_id'];
            }
        }

        if ($runIds !== []) {
            $runMeta = $this->batchLoadRunMeta(array_unique($runIds));

            $messages = array_map(function (Message $msg) use ($runMeta) {
                if ($msg->role === 'assistant' && $msg->runId !== null && isset($runMeta[$msg->runId])) {
                    return new Message(
                        role: $msg->role,
                        content: $msg->content,
                        timestamp: $msg->timestamp,
                        runId: $msg->runId,
                        meta: array_merge($msg->meta, $runMeta[$msg->runId]),
                        type: $msg->type,
                    );
                }

                return $msg;
            }, $messages);
        }

        return $messages;
    }

    /**
     * Batch-load run metadata from ai_runs for transcript hydration.
     *
     * @param  list<string>  $runIds
     * @return array<string, array<string, mixed>>
     */
    private function batchLoadRunMeta(array $runIds): array
    {
        $runs = AiRun::query()
            ->whereIn('id', $runIds)
            ->get()
            ->keyBy('id');

        $result = [];

        foreach ($runs as $id => $run) {
            $result[$id] = $this->buildMetaFromAiRun($run);
        }

        return $result;
    }

    /**
     * Build message-compatible meta array from an AiRun model.
     *
     * @return array<string, mixed>
     */
    private function buildMetaFromAiRun(AiRun $run): array
    {
        $meta = [
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
        ];

        if ($run->error_type !== null) {
            $meta['error'] = $run->error_message;
            $meta['error_type'] = $run->error_type;
            $meta['message_type'] = 'error';
        }

        if ($run->retry_attempts !== null) {
            $meta['retry_attempts'] = $run->retry_attempts;
        }

        if ($run->fallback_attempts !== null) {
            $meta['fallback_attempts'] = $run->fallback_attempts;
        }

        if ($run->tool_actions !== null) {
            $meta['tool_actions'] = $run->tool_actions;
        }

        return $meta;
    }

    /**
     * Extract minimal metadata to persist in transcript entries.
     *
     * Keeps the transcript self-sufficient for usage reconstruction
     * while ai_runs remains the indexed projection for queries.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function extractTranscriptMeta(array $meta): array
    {
        $tokens = $meta['tokens'] ?? null;

        if (! is_array($tokens)) {
            return [];
        }

        return ['tokens' => $tokens];
    }
}
