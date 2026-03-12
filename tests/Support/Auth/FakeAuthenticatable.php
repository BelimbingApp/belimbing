<?php

use Illuminate\Contracts\Auth\Authenticatable;

class FakeAuthenticatable implements Authenticatable
{
    private ?string $rememberToken = null;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private readonly int $id,
        private readonly array $attributes = [],
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return $this->rememberToken;
    }

    public function setRememberToken($value): void
    {
        $this->rememberToken = is_string($value) ? $value : null;
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}
