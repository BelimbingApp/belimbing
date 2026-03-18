<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\Message;
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

        if (is_string($runId) && $runId !== '' && $meta !== []) {
            $this->sessionManager->storeRunMeta($employeeId, $sessionId, $runId, $meta);
        }

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
            $data = json_decode($line, true);

            if (! is_array($data)) {
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
        $snippetLength = 120;
        $contentLength = mb_strlen($content);

        if ($contentLength <= $snippetLength) {
            return $content;
        }

        $halfWindow = (int) floor($snippetLength / 2);
        $start = max(0, $matchPos - $halfWindow);
        $end = min($contentLength, $start + $snippetLength);

        if ($end === $contentLength) {
            $start = max(0, $end - $snippetLength);
        }

        $snippet = mb_substr($content, $start, $end - $start);

        if ($start > 0) {
            $snippet = '…'.$snippet;
        }

        if ($end < $contentLength) {
            $snippet .= '…';
        }

        return $snippet;
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
        $runMetadata = $this->sessionManager->runMetadata($employeeId, $sessionId);

        foreach ($lines as $line) {
            $message = $this->messageFromTranscriptLine($line, $runMetadata);

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $runMetadata
     */
    private function messageFromTranscriptLine(string $line, array $runMetadata): ?Message
    {
        $data = json_decode($line, true);

        if (! is_array($data)) {
            return null;
        }

        return Message::fromJsonLine($this->enrichMessageMetadata($data, $runMetadata));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $runMetadata
     * @return array<string, mixed>
     */
    private function enrichMessageMetadata(array $data, array $runMetadata): array
    {
        $runId = $data['run_id'] ?? null;
        $lineMeta = $data['meta'] ?? null;

        if (($lineMeta === null || $lineMeta === []) && is_string($runId)) {
            $storedRun = $runMetadata[$runId] ?? null;
            $storedMeta = is_array($storedRun) ? ($storedRun['meta'] ?? null) : null;

            if (is_array($storedMeta)) {
                $data['meta'] = $storedMeta;
            }
        }

        return $data;
    }
}
