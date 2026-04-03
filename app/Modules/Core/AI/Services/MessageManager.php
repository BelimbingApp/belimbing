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
            meta: [],
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

            $messages[] = Message::fromJsonLine($data);

            if (($data['role'] ?? '') === 'assistant' && isset($data['run_id']) && ($data['meta'] ?? []) === []) {
                $runIds[] = $data['run_id'];
            }
        }

        if ($runIds !== []) {
            $runMeta = $this->batchLoadRunMeta(array_unique($runIds));

            $messages = array_map(function (Message $msg) use ($runMeta) {
                if ($msg->role === 'assistant' && $msg->runId !== null && $msg->meta === [] && isset($runMeta[$msg->runId])) {
                    return new Message(
                        role: $msg->role,
                        content: $msg->content,
                        timestamp: $msg->timestamp,
                        runId: $msg->runId,
                        meta: $runMeta[$msg->runId],
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
}
