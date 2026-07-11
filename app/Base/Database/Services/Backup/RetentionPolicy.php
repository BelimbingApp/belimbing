<?php

namespace App\Base\Database\Services\Backup;

/**
 * Retention plan for backup artifacts.
 *
 * Two knobs:
 *   - keep_days: drop artifacts older than this many days.
 *   - keep_count: always preserve at least this many of the most recent
 *     artifacts, regardless of age.
 *
 * Either can be 0 to disable that side of the policy.
 */
final readonly class RetentionPolicy
{
    public function __construct(
        public int $keepDays,
        public int $keepCount,
    ) {}

    /**
     * Decide which manifest entries should be deleted given a list sorted by
     * finished_at descending (most recent first).
     *
     * Each entry is an array with keys:
     *   - 'manifest_path' (string)
     *   - 'artifact_path' (string)
     *   - 'finished_at_unix' (int)
     *
     * Returns the entries that should be removed.
     *
     * @param  array<int, array{manifest_path: string, artifact_path: string, finished_at_unix: int}>  $entriesNewestFirst
     * @return array<int, array{manifest_path: string, artifact_path: string, finished_at_unix: int}>
     */
    public function selectExpired(array $entriesNewestFirst, int $now): array
    {
        if ($this->keepDays <= 0) {
            return [];
        }

        $threshold = $now - ($this->keepDays * 86400);
        $expired = [];

        foreach ($entriesNewestFirst as $index => $entry) {
            // Always preserve the keep_count newest, regardless of age.
            if ($this->keepCount > 0 && $index < $this->keepCount) {
                continue;
            }

            if ($entry['finished_at_unix'] > 0 && $entry['finished_at_unix'] < $threshold) {
                $expired[] = $entry;
            }
        }

        return $expired;
    }
}
