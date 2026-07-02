<?php

namespace App\Base\Menu\DTO;

final readonly class MenuLinkResolution
{
    private function __construct(
        public ?string $href,
        public ?string $reason = null,
        public ?string $error = null,
    ) {}

    public static function resolved(string $href): self
    {
        return new self($href);
    }

    public static function failed(string $reason, ?string $error = null): self
    {
        return new self(null, $reason, $error);
    }

    public function isResolved(): bool
    {
        return $this->href !== null;
    }

    /**
     * @return array<string, string>
     */
    public function failureContext(): array
    {
        if ($this->isResolved()) {
            return [];
        }

        return array_filter([
            'reason' => $this->reason,
            'error' => $this->error,
        ], static fn (?string $value): bool => $value !== null);
    }
}
