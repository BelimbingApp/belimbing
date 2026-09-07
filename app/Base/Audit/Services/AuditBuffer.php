<?php

namespace App\Base\Audit\Services;

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Models\AuditMutation;
use Illuminate\Database\Eloquent\Model;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Buffers audit entries and flushes them in batch INSERTs
 * after the response is sent.
 */
class AuditBuffer
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $pendingMutations = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $pendingActions = [];

    private bool $flushRegistered = false;

    /**
     * Buffer a mutation entry for deferred persistence.
     *
     * @param  array<string, mixed>  $entry
     */
    public function bufferMutation(array $entry): void
    {
        $this->pendingMutations[] = $entry;
        $this->ensureFlushRegistered();
    }

    /**
     * Buffer an action entry for deferred persistence.
     *
     * @param  array<string, mixed>  $entry
     */
    public function bufferAction(array $entry): void
    {
        $this->pendingActions[] = $entry;
        $this->ensureFlushRegistered();
    }

    /**
     * Register the deferred flush once per request.
     */
    private function ensureFlushRegistered(): void
    {
        if ($this->flushRegistered) {
            return;
        }

        $this->flushRegistered = true;
        defer(fn () => $this->flush(), 'audit.flush')->always();
    }

    /**
     * Flush all buffered entries in batch inserts.
     */
    private function flush(): void
    {
        $this->flushRegistered = false;

        $this->flushTable($this->pendingMutations, AuditMutation::class, 'mutation');
        $this->flushTable($this->pendingActions, AuditAction::class, 'action');
    }

    /**
     * Batch-insert entries for a single table.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @param  class-string<Model>  $model
     */
    private function flushTable(array &$entries, string $model, string $type): void
    {
        if (empty($entries)) {
            return;
        }

        $batch = $entries;
        $entries = [];

        $keys = [];
        foreach ($batch as $entry) {
            foreach (array_keys($entry) as $key) {
                $keys[$key] = true;
            }
        }
        $keys = array_keys($keys);
        sort($keys);

        $normalized = array_map(static function (array $entry) use ($keys): array {
            $row = [];
            foreach ($keys as $key) {
                $row[$key] = $entry[$key] ?? ($key === 'is_retained' ? false : null);
            }

            return $row;
        }, $batch);

        try {
            foreach (array_chunk($normalized, 500) as $chunk) {
                $model::query()->insert($chunk);
            }
        } catch (Throwable $exception) {
            logger()->error("Audit {$type} log batch persistence failed.", [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'count' => count($batch),
            ]);
        }
    }
}
