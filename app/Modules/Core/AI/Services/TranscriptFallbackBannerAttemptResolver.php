<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\Message;

/**
 * Resolves whether a session-level fallback warning should reflect the latest assistant outcome.
 *
 * Only the chronologically latest assistant transcript line with type `message` is considered.
 * If that line has no `fallback_attempts` metadata (or an empty list), no sticky warning is
 * warranted — for example after a later successful run without fallback once limits clear.
 *
 * If that line is a terminal error (`error_type` set), no sticky warning is shown either: the
 * error bubble is authoritative, and `fallback_attempts` may only describe earlier transient
 * failures that are not the final outcome.
 */
final class TranscriptFallbackBannerAttemptResolver
{
    /**
     * @param  list<Message>  $messages  Transcript order (oldest first)
     * @return array<string, mixed>|null Last entry from the line's fallback_attempts list
     */
    public static function latestFailureAttempt(array $messages): ?array
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $message = $messages[$i];

            if ($message->role !== 'assistant' || $message->type !== 'message') {
                continue;
            }

            $errorType = $message->getMetaString('error_type');
            if (is_string($errorType) && $errorType !== '') {
                return null;
            }

            $attempts = $message->getMetaArray('fallback_attempts');

            if ($attempts === []) {
                return null;
            }

            $last = end($attempts);

            return is_array($last) ? $last : null;
        }

        return null;
    }
}
