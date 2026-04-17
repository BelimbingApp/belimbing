<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\User\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ChatAttachmentController
{
    public function __invoke(Request $request, int $employeeId, string $sessionId, string $attachmentId): BinaryFileResponse
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        $actor = Actor::forUser($user);
        $allowed = app(AuthorizationService::class)->can($actor, 'ai.chat_attachments.manage')->allowed;
        if (! $allowed) {
            abort(403);
        }

        $sessionManager = app(SessionManager::class);
        if ($sessionManager->get($employeeId, $sessionId) === null) {
            abort(404);
        }

        $dir = $sessionManager->sessionsPath($employeeId).'/attachments/'.$sessionId;
        if (! is_dir($dir)) {
            abort(404);
        }

        $path = $this->locateAttachmentPath($dir, $attachmentId);
        if ($path === null) {
            abort(404);
        }

        $mimeType = (string) ($request->query('mime', ''));
        $isImage = str_starts_with($mimeType, 'image/');
        $disposition = $isImage ? 'inline' : 'attachment';

        return response()->file($path, [
            'Content-Disposition' => $disposition,
        ]);
    }

    private function locateAttachmentPath(string $dir, string $attachmentId): ?string
    {
        $prefix = $attachmentId.'_';

        foreach (scandir($dir) ?: [] as $file) {
            if (! is_string($file) || $file === '.' || $file === '..') {
                continue;
            }

            if (! str_starts_with($file, $prefix)) {
                continue;
            }

            $full = $dir.'/'.$file;
            if (is_file($full)) {
                return $full;
            }
        }

        return null;
    }
}
