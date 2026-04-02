<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Jobs\RunBackgroundCommandJob;
use App\Modules\Core\AI\Models\OperationDispatch;
use Illuminate\Support\Str;

/**
 * Policy-bound background artisan command execution.
 *
 * Validates commands against a configurable allowlist, creates durable
 * OperationDispatch records, and queues RunBackgroundCommandJob for
 * actual execution. The ArtisanTool delegates background mode here
 * instead of returning synthetic stub IDs.
 *
 * Key invariant: only allowlisted commands can be dispatched. The
 * allowlist uses prefix matching so "migrate" permits "migrate:status",
 * "migrate:fresh", etc.
 */
class BackgroundCommandService
{
    /**
     * Default allowlist of artisan command prefixes.
     *
     * Commands matching any prefix are permitted for background dispatch.
     * Configurable via config('ai.tools.artisan.background_allowlist').
     *
     * @var list<string>
     */
    private const DEFAULT_ALLOWLIST = [
        'blb:',
        'migrate:status',
        'route:list',
        'config:show',
        'schedule:list',
        'queue:',
    ];

    /**
     * Dispatch an artisan command for background execution.
     *
     * Creates a durable OperationDispatch record and queues the job.
     * Returns the dispatch record for the caller to format.
     *
     * @param  string  $command  The artisan command (without "php artisan" prefix)
     * @param  int|null  $actingForUserId  User on whose behalf the command runs
     *
     * @throws \InvalidArgumentException If the command is not in the allowlist
     */
    public function dispatch(string $command, ?int $actingForUserId = null): OperationDispatch
    {
        $this->assertAllowed($command);

        $dispatch = OperationDispatch::query()->create([
            'id' => OperationDispatch::ID_PREFIX.Str::random(12),
            'operation_type' => OperationType::BackgroundCommand,
            'employee_id' => null,
            'acting_for_user_id' => $actingForUserId,
            'task' => 'php artisan '.$command,
            'status' => OperationStatus::Queued,
            'meta' => [
                'command' => $command,
                'source' => 'artisan_tool',
            ],
        ]);

        RunBackgroundCommandJob::dispatch($dispatch->id);

        return $dispatch;
    }

    /**
     * Check whether a command is permitted for background execution.
     *
     * @param  string  $command  Artisan command string
     */
    public function isAllowed(string $command): bool
    {
        $baseCommand = $this->extractBaseCommand($command);

        foreach ($this->allowlist() as $prefix) {
            if (str_starts_with($baseCommand, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the current allowlist.
     *
     * @return list<string>
     */
    public function allowlist(): array
    {
        $configured = config('ai.tools.artisan.background_allowlist');

        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return self::DEFAULT_ALLOWLIST;
    }

    /**
     * Assert that a command is permitted for background execution.
     *
     * @throws \InvalidArgumentException If the command is not allowed
     */
    private function assertAllowed(string $command): void
    {
        if (! $this->isAllowed($command)) {
            throw new \InvalidArgumentException(
                'Command "'.$this->extractBaseCommand($command).'" is not permitted for background execution. '
                .'Allowed command prefixes: '.implode(', ', $this->allowlist()).'.',
            );
        }
    }

    /**
     * Extract the base command name (before arguments/options).
     */
    private function extractBaseCommand(string $command): string
    {
        $parts = preg_split('/\s+/', trim($command), 2);

        return $parts[0] ?? '';
    }
}
