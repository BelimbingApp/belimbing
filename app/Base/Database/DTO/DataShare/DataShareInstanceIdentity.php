<?php

namespace App\Base\Database\DTO\DataShare;

use App\Base\Database\Enums\DataShareInstanceRole;

final readonly class DataShareInstanceIdentity
{
    public function __construct(
        public string $id,
        public string $name,
        public DataShareInstanceRole $role,
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
