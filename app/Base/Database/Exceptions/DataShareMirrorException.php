<?php

namespace App\Base\Database\Exceptions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class DataShareMirrorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode = 'mirror_failed',
        public readonly bool $outcomeIndeterminate = false,
        ?Throwable $previous = null,
        public readonly ?string $diagnosticReference = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function unexpected(string $operation, Throwable $previous, bool $outcomeIndeterminate = false): self
    {
        $reference = Str::upper(Str::random(8));
        Log::error('Unexpected Data Share mirror failure.', [
            'diagnostic_reference' => $reference,
            'operation' => $operation,
            'exception' => $previous,
        ]);
        $message = match ($operation) {
            'catalog' => __('An unexpected database error prevented the mirror catalog from being inspected.'),
            'review' => __('An unexpected database error prevented the selected tables from being reviewed. No data was changed.'),
            'execute' => __('An unexpected error interrupted the mirror operation. The commit outcome could not be confirmed.'),
            'connection' => __('An unexpected error prevented the mirror connection from being verified.'),
            'initialize' => __('An unexpected error interrupted mirror schema preparation. The schema state could not be confirmed; no table data was mirrored.'),
            'supabase_discovery' => __('An unexpected error prevented Belimbing from asking Supabase for the account’s organizations and projects.'),
            'supabase_create' => __('Supabase project setup stopped unexpectedly. Check the Supabase project list before retrying because project creation could not be confirmed.'),
            'supabase_connect' => __('An unexpected error prevented Belimbing from connecting the selected Supabase project.'),
            default => __('An unexpected Data Share mirror error occurred.'),
        };

        return new self(
            $message.' '.__('Diagnostic reference: :reference.', ['reference' => $reference]),
            'internal_error',
            $outcomeIndeterminate,
            $previous,
            $reference,
        );
    }

    public static function unavailable(string $message): self
    {
        return new self($message, 'unavailable');
    }

    public static function invalidDirection(): self
    {
        return new self(__('Mirror direction must be push or pull.'), 'invalid_direction');
    }

    public static function emptySelection(): self
    {
        return new self(__('Select at least one exact table to mirror.'), 'empty_selection');
    }

    public static function invalidSelection(string $message): self
    {
        return new self($message, 'invalid_selection');
    }

    public static function blocked(): self
    {
        return new self(__('The selected tables are blocked. Resolve every dependency issue and review again.'), 'blocked');
    }

    public static function staleReview(): self
    {
        return new self(__('The table state changed after review. Review the selected tables again before executing.'), 'stale_review');
    }

    public static function lockUnavailable(): self
    {
        return new self(__('Another mirror operation is running. Try again after it finishes.'), 'lock_unavailable');
    }

    public static function initializationFailed(?Throwable $previous = null): self
    {
        if ($previous !== null) {
            return self::unexpected('initialize', $previous, true);
        }

        return new self(__('Mirror schema preparation failed. The schema state could not be confirmed; no table data was mirrored.'), 'initialization_failed', true);
    }

    public static function initializationCommandFailed(int $exitCode): self
    {
        return new self(
            __('The mirror migration command exited with code :code. The schema state could not be confirmed; no table data was mirrored.', ['code' => $exitCode]),
            'initialization_failed',
            true,
        );
    }

    public static function alreadyInitialized(): self
    {
        return new self(__('The provider is already initialized and ready. No schema or identity was changed.'), 'already_initialized');
    }

    public static function limitExceeded(string $message): self
    {
        return new self($message, 'transfer_limit_exceeded');
    }

    public static function safeFailure(string $message, ?Throwable $previous = null): self
    {
        return new self($message, 'mirror_failed', false, $previous);
    }

    public static function preMutationProcessFailed(string $tool, ?int $exitCode = null, ?Throwable $previous = null): self
    {
        $suffix = $exitCode === null ? '' : ' '.__('(exit :code)', ['code' => $exitCode]);

        return new self(__(':tool could not prepare the mirror operation.', ['tool' => $tool]).$suffix, 'process_failed', false, $previous);
    }

    public static function processFailed(string $tool, ?int $exitCode = null, ?Throwable $previous = null): self
    {
        $suffix = $exitCode === null ? '' : ' '.__('(exit :code)', ['code' => $exitCode]);

        return new self(__(':tool could not complete the mirror operation.', ['tool' => $tool]).$suffix, 'process_failed', true, $previous);
    }
}
