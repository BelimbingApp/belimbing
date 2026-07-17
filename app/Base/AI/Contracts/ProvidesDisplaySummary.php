<?php

namespace App\Base\AI\Contracts;

/**
 * Optional Tool add-on: a human-readable one-liner for a specific invocation.
 *
 * Chat transcripts show raw JSON arguments by default; tools implementing
 * this contract get a legible title line instead ("Read app/Models/User.php",
 * "$ php artisan migrate:status") with the raw arguments kept behind an
 * expander for full transparency.
 *
 * Implementations must never throw and must not leak secrets — arguments
 * arrive exactly as the LLM produced them and may be malformed.
 */
interface ProvidesDisplaySummary
{
    /**
     * @param  array<string, mixed>  $arguments  Parsed tool-call arguments
     */
    public function displaySummary(array $arguments): string;
}
