<?php

namespace App\Base\Support\Git;

/**
 * Outcome of a single git invocation.
 */
final readonly class GitResult
{
    public function __construct(
        public bool $ok,
        public string $output,
        public string $error,
        public int $exitCode,
    ) {}

    /**
     * A no-op success, e.g. when there was nothing to commit.
     */
    public static function skipped(): self
    {
        return new self(ok: true, output: '', error: '', exitCode: 0);
    }

    /**
     * The most useful human-readable line: stderr, else stdout, else the code.
     */
    public function message(): string
    {
        return match (true) {
            $this->error !== '' => $this->error,
            $this->output !== '' => $this->output,
            default => (string) __('git exited with code :code', ['code' => $this->exitCode]),
        };
    }
}
