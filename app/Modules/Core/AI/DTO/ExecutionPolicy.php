<?php

namespace App\Modules\Core\AI\DTO;

use App\Modules\Core\AI\Enums\ExecutionMode;

/**
 * Execution policy for a single AI run.
 *
 * Controls timeout budget, execution mode, and retry eligibility.
 * When not provided, the runtime falls back to config-driven defaults
 * resolved via `ExecutionPolicy::fromConfig()`.
 */
final readonly class ExecutionPolicy
{
    public function __construct(
        public ExecutionMode $mode,
        public int $timeoutSeconds,
        public bool $allowRetry = true,
    ) {}

    /**
     * Build a policy from the three-tier timeout config.
     *
     * Reads `ai.llm.timeout_tiers.{mode}` with sensible defaults.
     */
    public static function forMode(ExecutionMode $mode): self
    {
        $tiers = config('ai.llm.timeout_tiers', []);

        $timeout = match ($mode) {
            ExecutionMode::Interactive => (int) ($tiers['interactive'] ?? 60),
            ExecutionMode::HeavyForeground => (int) ($tiers['heavy_foreground'] ?? 180),
            ExecutionMode::Background => (int) ($tiers['background'] ?? 600),
        };

        return new self(
            mode: $mode,
            timeoutSeconds: $timeout,
            allowRetry: $mode->isForeground(),
        );
    }

    /**
     * Default interactive policy — the common case for chat.
     */
    public static function interactive(): self
    {
        return self::forMode(ExecutionMode::Interactive);
    }

    /**
     * Heavy foreground policy — multi-tool analysis, image analysis.
     */
    public static function heavyForeground(): self
    {
        return self::forMode(ExecutionMode::HeavyForeground);
    }

    /**
     * Background policy — doc drafting, complex coding, multi-file edits.
     */
    public static function background(): self
    {
        return self::forMode(ExecutionMode::Background);
    }
}
