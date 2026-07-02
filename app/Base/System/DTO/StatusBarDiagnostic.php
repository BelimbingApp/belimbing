<?php

namespace App\Base\System\DTO;

use App\Base\Foundation\Enums\StatusVariant;

final readonly class StatusBarDiagnostic
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $id,
        public StatusVariant $severity,
        public string $source,
        public string $summary,
        public ?string $detail = null,
        public ?string $target = null,
        public array $metadata = [],
    ) {}

    public function severityRank(): int
    {
        return match ($this->severity) {
            StatusVariant::Error => 30,
            StatusVariant::Warning => 20,
            StatusVariant::Info => 10,
            StatusVariant::Success => 0,
        };
    }

    public function severityLabel(): string
    {
        return $this->severity === StatusVariant::Error
            ? 'danger'
            : $this->severity->value;
    }
}
