<?php

namespace App\Base\AI\DTO;

/**
 * Result of bounded text extraction from one public document URL.
 */
final readonly class DocumentExtractionResult
{
    private function __construct(
        public ?string $content,
        public ?string $sourceUrl,
        public ?string $mediaType,
        public int $sourceBytes,
        public int $charCount,
        public bool $truncated,
        public ?string $pageSelection,
        public bool $defaultPageWindow,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}

    public static function success(
        string $content,
        string $sourceUrl,
        string $mediaType,
        int $sourceBytes,
        bool $truncated = false,
        ?string $pageSelection = null,
        bool $defaultPageWindow = false,
    ): self {
        return new self(
            content: $content,
            sourceUrl: $sourceUrl,
            mediaType: $mediaType,
            sourceBytes: $sourceBytes,
            charCount: mb_strlen($content),
            truncated: $truncated,
            pageSelection: $pageSelection,
            defaultPageWindow: $defaultPageWindow,
            errorCode: null,
            errorMessage: null,
        );
    }

    public static function failure(string $errorCode, string $errorMessage): self
    {
        return new self(
            content: null,
            sourceUrl: null,
            mediaType: null,
            sourceBytes: 0,
            charCount: 0,
            truncated: false,
            pageSelection: null,
            defaultPageWindow: false,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    public function successful(): bool
    {
        return $this->errorCode === null;
    }
}
