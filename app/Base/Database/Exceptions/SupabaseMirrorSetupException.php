<?php

namespace App\Base\Database\Exceptions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SupabaseMirrorSetupException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode = 'supabase_setup_failed',
        ?Throwable $previous = null,
        public readonly ?string $diagnosticReference = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidToken(): self
    {
        return new self(__('Supabase did not accept this personal access token. Create a new token in your Supabase account and try again.'), 'invalid_token');
    }

    public static function forbidden(): self
    {
        return new self(__('This Supabase personal access token cannot manage the selected organization or project. Check its permissions or use another token.'), 'forbidden');
    }

    public static function rateLimited(): self
    {
        return new self(__('Supabase is receiving too many setup requests. Wait a minute, then try again.'), 'rate_limited');
    }

    public static function billingRequired(): self
    {
        return new self(__('Supabase could not create the project for this organization. Review its plan and billing in Supabase, then try again.'), 'billing_required');
    }

    public static function unavailable(?Throwable $previous = null): self
    {
        if ($previous === null) {
            return new self(__('The Supabase Management API returned an unexpected response.'), 'unavailable');
        }

        $reference = Str::upper(Str::random(8));
        Log::error('Unexpected Supabase Management API connection failure.', [
            'diagnostic_reference' => $reference,
            'exception' => $previous,
        ]);

        return new self(
            __('Belimbing could not reach the Supabase Management API. Check this machine’s internet and DNS connection. Diagnostic reference: :reference.', ['reference' => $reference]),
            'unavailable',
            $previous,
            $reference,
        );
    }

    public static function apiError(int $status): self
    {
        return new self(
            __('The Supabase Management API returned HTTP :status instead of completing the request. Try again later or check Supabase status.', ['status' => $status]),
            'api_error',
        );
    }

    public static function invalidResponse(): self
    {
        return new self(__('Supabase returned an incomplete setup response. Try again or connect to an existing mirror.'), 'invalid_response');
    }

    public static function databaseUnavailable(string $message): self
    {
        return new self($message, 'database_unavailable');
    }
}
