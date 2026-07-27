<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

final readonly class DataShareMirrorBlocker
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $relatedTable = null,
    ) {}

    /** @return array{code: string, message: string, related_table: string|null} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'related_table' => $this->relatedTable,
        ];
    }
}
