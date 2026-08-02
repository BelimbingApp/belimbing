<?php

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
 * allowlist uses prefix matching on the parsed command name (first
 * token) so "migrate" permits "migrate:status", "migrate:fresh", etc.
 * Commands are parsed into tokens and stored so the queue worker can
 * execute them as an array, avoiding shell interpretation entirely.
 */
class BackgroundCommandService
{
    /**
     * Default allowlist of artisan command name prefixes.
     *
     * Command names matching any prefix are permitted for background dispatch.
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
     * The command name (first parsed token) is checked against the allowlist
     * using prefix matching. Shell metacharacters in the raw string are
     * rejected outright since they indicate an attempt to escape the artisan
     * command boundary.
     *
     * @param  string  $command  Artisan command string
     */
    public function isAllowed(string $command): bool
    {
        if ($this->containsShellMetacharacters($command)) {
            return false;
        }

        $tokens = ArtisanCommandLine::tokenize($command);
        $baseCommand = $tokens[0] ?? '';

        if ($baseCommand === '') {
            return false;
        }

        foreach ($this->allowlist() as $prefix) {
            if ($this->matchesPrefix($baseCommand, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match an allowlist entry at a command-namespace boundary.
     *
     * A bare `str_starts_with` would let `migrateevil` through on a `migrate`
     * entry and `route:listevil` through on `route:list`. The entry must match
     * the whole command name, or be a namespace it sits under — so `migrate`
     * still permits `migrate:status`, and `queue:` still permits `queue:work`.
     */
    private function matchesPrefix(string $baseCommand, string $prefix): bool
    {
        if ($prefix === '') {
            return false;
        }

        if ($baseCommand === $prefix) {
            return true;
        }

        if (! str_starts_with($baseCommand, $prefix)) {
            return false;
        }

        return str_ends_with($prefix, ':') || substr($baseCommand, strlen($prefix), 1) === ':';
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
                'Command is not permitted for background execution. '
                .'Allowed command prefixes: '.implode(', ', $this->allowlist()).'.',
            );
        }
    }

    /**
     * Detect shell metacharacters that could escape the artisan command boundary.
     */
    private function containsShellMetacharacters(string $command): bool
    {
        return preg_match('/[;&|`$(){}<>\n]/', $command) === 1;
    }
}
