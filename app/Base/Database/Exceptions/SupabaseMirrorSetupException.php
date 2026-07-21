<?php

namespace App\Base\Database\Exceptions;

use RuntimeException;
use Throwable;

final class SupabaseMirrorSetupException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reasonCode = 'supabase_setup_failed',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function invalidToken(): self
    {
        return new self(__('Supabase did not accept this access token. Create a new token and try again.'), 'invalid_token');
    }

    public static function forbidden(): self
    {
        return new self(__('This Supabase token does not have permission for the selected organization or project.'), 'forbidden');
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
        return new self(__('Supabase setup is unavailable right now. Check the network connection and try again.'), 'unavailable', $previous);
    }

    public static function invalidResponse(): self
    {
        return new self(__('Supabase returned an incomplete setup response. Try again or use the advanced connection option.'), 'invalid_response');
    }

    public static function databaseUnavailable(string $message): self
    {
        return new self($message, 'database_unavailable');
    }
}
