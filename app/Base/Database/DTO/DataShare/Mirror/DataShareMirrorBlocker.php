<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

final readonly class DataShareMirrorBlocker
{
    public function __construct(
        public string $code,
        public string $message,
    ) {}

    /** @return array{code: string, message: string} */
    public function toArray(): array
    {
        return ['code' => $this->code, 'message' => $this->message];
    }
}
