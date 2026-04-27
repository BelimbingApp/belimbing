<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Audit\Services;

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Models\AuditMutation;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Throwable;

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

    public function __construct(private readonly Application $app) {}

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
     * Register the terminating callback once per request.
     */
    private function ensureFlushRegistered(): void
    {
        if ($this->flushRegistered) {
            return;
        }

        $this->flushRegistered = true;
        $this->app->terminating(function (): void {
            $this->flush();
        });
    }

    /**
     * Flush all buffered entries in batch inserts.
     */
    private function flush(): void
    {
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

        try {
            foreach (array_chunk($batch, 500) as $chunk) {
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
