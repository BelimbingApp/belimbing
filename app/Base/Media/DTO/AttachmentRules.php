<?php

namespace App\Base\Media\DTO;

final readonly class AttachmentRules
{
    /**
     * @param  list<string>  $allowedMimeTypes
     */
    public function __construct(
        public int $maxBytes = 10_485_760,
        public array $allowedMimeTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'text/plain',
            'text/csv',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ],
    ) {
        if ($this->maxBytes < 1) {
            throw new \InvalidArgumentException('Attachment maximum size must be at least one byte.');
        }
    }
}
