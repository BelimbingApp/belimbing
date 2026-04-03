<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\DTO;

use DateTimeImmutable;

final readonly class Session
{
    /**
     * @param  array{strategy: string, provider_name: string, model: string, resolved_at: string, last_changed_at: string}|null  $llm
     */
    public function __construct(
        public string $id,
        public int $employeeId,
        public string $channelType,
        public ?string $title,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastActivityAt,
        public int $transcriptVersion = 1,
        public ?array $llm = null,
    ) {}

    /**
     * Create from a decoded meta.json array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromMeta(array $data): self
    {
        return new self(
            id: $data['id'],
            employeeId: $data['employee_id'],
            channelType: $data['channel_type'] ?? 'web',
            title: $data['title'] ?? null,
            createdAt: new DateTimeImmutable($data['created_at']),
            lastActivityAt: new DateTimeImmutable($data['last_activity_at']),
            transcriptVersion: (int) ($data['transcript_version'] ?? 1),
            llm: is_array($data['llm'] ?? null) ? $data['llm'] : null,
        );
    }

    /**
     * Serialize to array for meta.json persistence.
     *
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
        $meta = [
            'id' => $this->id,
            'employee_id' => $this->employeeId,
            'channel_type' => $this->channelType,
            'title' => $this->title,
            'created_at' => $this->createdAt->format('c'),
            'last_activity_at' => $this->lastActivityAt->format('c'),
            'transcript_version' => $this->transcriptVersion,
        ];

        if (is_array($this->llm)) {
            $meta['llm'] = $this->llm;
        }

        return $meta;
    }
}
