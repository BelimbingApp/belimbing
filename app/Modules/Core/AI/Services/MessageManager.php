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
     * @param  int  $employeeId  Digital Worker employee ID
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
     * @param  int  $employeeId  Digital Worker employee ID
     * @param  string  $sessionId  Session UUID
     * @param  string  $content  Message content
     */
    public function appendUserMessage(int $employeeId, string $sessionId, string $content): Message
    {
        $message = new Message(
            role: 'user',
            content: $content,
            timestamp: new DateTimeImmutable,
        );

        $this->append($employeeId, $sessionId, $message);

        return $message;
    }

    /**
     * Append an assistant message to a session transcript.
     *
     * @param  int  $employeeId  Digital Worker employee ID
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
     * Read all messages from a session transcript in order.
     *
     * @param  int  $employeeId  Digital Worker employee ID
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
