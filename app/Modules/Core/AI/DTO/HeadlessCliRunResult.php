<?php

namespace App\Modules\Core\AI\DTO;

final readonly class HeadlessCliRunResult
{
    /**
     * @param  array<string, mixed>|null  $usage
     */
    public function __construct(
        public int $exitCode,
        public string $stdout,
        public string $stderr,
        public ?string $result,
        public ?float $costUsd,
        public ?array $usage,
        public string $provider,
        public string $model,
        public string $identitySource,
        public string $command,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }

    public function outputSummary(): string
    {
        $summary = $this->result ?? trim($this->stdout);

        if ($summary === '') {
            $summary = trim($this->stderr);
        }

        return mb_substr($summary, 0, 10000);
    }
}
