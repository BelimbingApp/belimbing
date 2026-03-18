<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Handles file attachment validation, storage, and text extraction.
 */
trait HandlesAttachments
{
    /**
     * Remove a pending attachment by index before sending.
     */
    public function removeAttachment(int $index): void
    {
        if (isset($this->attachments[$index])) {
            unset($this->attachments[$index]);
            $this->attachments = array_values($this->attachments);
        }
    }

    /**
     * Check if the current user has attachment upload capability.
     */
    public function canAttachFiles(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $actor = Actor::forUser($user);

        return app(AuthorizationService::class)->can($actor, 'ai.chat_attachments.manage')->allowed;
    }

    /**
     * Process pending attachments: validate, store to session workspace, extract text for documents.
     *
     * @return list<array{id: string, original_name: string, stored_path: string, mime_type: string, size: int, kind: string, extracted_text_path: string|null}>
     */
    private function processAttachments(string $sessionId): array
    {
        $sessionManager = app(SessionManager::class);
        $basePath = $sessionManager->sessionsPath($this->employeeId);
        $attachDir = $basePath.'/attachments/'.$sessionId;

        if (! is_dir($attachDir)) {
            mkdir($attachDir, 0755, true);
        }

        $processed = [];

        foreach ($this->attachments as $file) {
            if (! $file instanceof TemporaryUploadedFile) {
                continue;
            }

            $mime = $file->getMimeType() ?? '';
            $size = $file->getSize() ?? 0;

            if (! in_array($mime, self::ATTACHMENT_MIMES, true) || $size > self::ATTACHMENT_MAX_SIZE) {
                continue;
            }

            $id = 'att_'.Str::random(12);
            $originalName = $file->getClientOriginalName();
            $storedPath = $attachDir.'/'.$id.'_'.$originalName;

            $file->storeAs(
                path: '',
                name: $id.'_'.$originalName,
                options: ['disk' => 'local', 'path' => $attachDir],
            );

            // Livewire storeAs uses the configured disk; copy to workspace directly
            copy($file->getRealPath(), $storedPath);

            $isImage = str_starts_with($mime, 'image/');
            $extractedTextPath = null;

            if (! $isImage) {
                $extractedTextPath = $this->extractTextFromFile($storedPath, $mime, $attachDir, $id);
            }

            $processed[] = [
                'id' => $id,
                'original_name' => $originalName,
                'stored_path' => $storedPath,
                'mime_type' => $mime,
                'size' => $size,
                'kind' => $isImage ? 'image' : 'document',
                'extracted_text_path' => $extractedTextPath,
            ];
        }

        return $processed;
    }

    /**
     * Extract readable text from a document file and write a sidecar .txt file.
     */
    private function extractTextFromFile(string $filePath, string $mimeType, string $attachDir, string $id): ?string
    {
        $text = null;

        if (in_array($mimeType, ['text/plain', 'text/csv', 'text/markdown', 'application/json'], true)) {
            $text = file_get_contents($filePath);
        } elseif ($mimeType === 'application/pdf') {
            $text = $this->extractPdfText($filePath);
        }

        if ($text === null || $text === false || trim($text) === '') {
            return null;
        }

        $sidecarPath = $attachDir.'/'.$id.'.extracted.txt';
        file_put_contents($sidecarPath, $text);

        return $sidecarPath;
    }

    /**
     * Extract text from a PDF file using pdftotext if available.
     */
    private function extractPdfText(string $filePath): ?string
    {
        $binary = trim((string) shell_exec('which pdftotext 2>/dev/null'));

        if ($binary === '') {
            return null;
        }

        $escaped = escapeshellarg($filePath);
        $output = shell_exec("{$binary} {$escaped} - 2>/dev/null");

        return is_string($output) && trim($output) !== '' ? $output : null;
    }
}
