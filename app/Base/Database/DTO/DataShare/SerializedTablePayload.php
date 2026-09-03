<?php

namespace App\Base\Database\DTO\DataShare;

final readonly class SerializedTablePayload
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $schema  the table's schema block as written into the payload header,
     *                                        kept here so a preview can advise on columns without
     *                                        introspecting the table a second time
     */
    public function __construct(
        public string $path,
        public array $metadata,
        public array $schema = [],
    ) {}
}
