<?php
namespace App\Base\Pdf\ValueObjects;

use DateTimeImmutable;

final readonly class PdfArtifact
{
    public function __construct(
        public string $disk,
        public string $path,
        public string $templateVersion,
        public string $dataVersion,
        public int $bytes,
        public string $sha256,
        public ?int $producedBy,
        public DateTimeImmutable $producedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'disk' => $this->disk,
            'path' => $this->path,
            'template_version' => $this->templateVersion,
            'data_version' => $this->dataVersion,
            'bytes' => $this->bytes,
            'sha256' => $this->sha256,
            'produced_by' => $this->producedBy,
            'produced_at' => $this->producedAt->format(DATE_ATOM),
        ];
    }
}
