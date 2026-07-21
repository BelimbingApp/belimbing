<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;
use Throwable;

final class DataShareMirrorException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode = 'mirror_failed',
        public readonly bool $outcomeIndeterminate = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
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
        return new self(__('The provider schema could not be initialized. No table data was mirrored.'), 'initialization_failed', false, $previous);
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
