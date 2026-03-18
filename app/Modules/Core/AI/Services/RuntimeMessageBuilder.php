<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\Message;

/**
 * Builds OpenAI-compatible message payloads from BLB runtime messages.
 *
 * Handles plain text messages and messages with attachments (images as
 * base64 vision blocks, documents as appended extracted text).
 */
class RuntimeMessageBuilder
{
    /**
     * Build the messages array for the OpenAI API.
     *
     * @param  list<Message>  $messages
     * @return list<array{role: string, content: string|array<int, array<string, mixed>>}>
     */
    public function build(array $messages, ?string $systemPrompt): array
    {
        $apiMessages = [];

        if ($systemPrompt !== null) {
            $apiMessages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($messages as $message) {
            if ($message->role === 'user') {
                $apiMessages[] = $this->buildUserMessage($message);
            } elseif ($message->role === 'assistant') {
                $apiMessages[] = [
                    'role' => 'assistant',
                    'content' => $message->content,
                ];
            }
        }

        return $apiMessages;
    }

    /**
     * Build a user message payload, handling attachments when present.
     *
     * Images are encoded as base64 data URLs in OpenAI vision format.
     * Documents have their extracted text appended to the user's message.
     *
     * @return array{role: string, content: string|list<array<string, mixed>>}
     */
    private function buildUserMessage(Message $message): array
    {
        $attachments = $message->meta['attachments'] ?? [];

        if ($attachments === []) {
            return ['role' => 'user', 'content' => $message->content];
        }

        [$imageBlocks, $documentTexts] = $this->partitionAttachments($attachments);

        // If we have images, use the multi-content vision format
        if ($imageBlocks !== []) {
            $content = [];

            $textPart = $message->content;
            if ($documentTexts !== []) {
                $textPart .= "\n\n".implode("\n\n", $documentTexts);
            }

            if ($textPart !== '') {
                $content[] = ['type' => 'text', 'text' => $textPart];
            }

            array_push($content, ...$imageBlocks);

            return ['role' => 'user', 'content' => $content];
        }

        // Documents only — append extracted text to user message
        $textContent = $message->content;
        if ($documentTexts !== []) {
            $textContent .= "\n\n".implode("\n\n", $documentTexts);
        }

        return ['role' => 'user', 'content' => $textContent];
    }

    /**
     * Split attachments into image blocks and document texts.
     *
     * @param  list<array<string, mixed>>  $attachments
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function partitionAttachments(array $attachments): array
    {
        $imageBlocks = [];
        $documentTexts = [];

        foreach ($attachments as $attachment) {
            if (($attachment['kind'] ?? '') === 'image') {
                $block = $this->buildImageBlock($attachment);
                if ($block !== null) {
                    $imageBlocks[] = $block;
                }
            } elseif (($attachment['kind'] ?? '') === 'document') {
                $text = $this->buildDocumentText($attachment);
                if ($text !== null) {
                    $documentTexts[] = $text;
                }
            }
        }

        return [$imageBlocks, $documentTexts];
    }

    /**
     * Build an OpenAI vision image_url content block from an attachment.
     *
     * @param  array<string, mixed>  $attachment
     * @return array{type: string, image_url: array{url: string}}|null
     */
    private function buildImageBlock(array $attachment): ?array
    {
        $path = $attachment['stored_path'] ?? null;

        if (! is_string($path) || ! file_exists($path)) {
            return null;
        }

        $data = file_get_contents($path);
        if ($data === false) {
            return null;
        }

        $mime = $attachment['mime_type'] ?? 'image/png';
        $base64 = base64_encode($data);

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => "data:{$mime};base64,{$base64}",
            ],
        ];
    }

    /**
     * Build document text block from an attachment's extracted text sidecar.
     *
     * @param  array<string, mixed>  $attachment
     */
    private function buildDocumentText(array $attachment): ?string
    {
        $extractedPath = $attachment['extracted_text_path'] ?? null;

        if (! is_string($extractedPath) || ! file_exists($extractedPath)) {
            return null;
        }

        $text = file_get_contents($extractedPath);
        if ($text === false || trim($text) === '') {
            return null;
        }

        $name = $attachment['original_name'] ?? 'document';

        // Truncate extracted text to prevent prompt explosion
        $maxChars = 50000;
        if (mb_strlen($text) > $maxChars) {
            $text = mb_substr($text, 0, $maxChars)."\n\n[... truncated at {$maxChars} characters]";
        }

        return "[Attached document: {$name}]\n{$text}";
    }
}
