<?php
namespace App\Base\Authz\DTO;

final readonly class ResourceContext
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $type,
        public int|string|null $id,
        public ?int $companyId = null,
        public array $attributes = [],
    ) {}
}
