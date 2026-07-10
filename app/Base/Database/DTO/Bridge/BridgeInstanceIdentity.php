<?php

namespace App\Base\Database\DTO\Bridge;

use App\Base\Database\Enums\BridgeInstanceRole;

final readonly class BridgeInstanceIdentity
{
    public function __construct(
        public string $id,
        public string $name,
        public BridgeInstanceRole $role,
    ) {}

    /** @return array{id: string, name: string, role: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role->value,
        ];
    }
}
