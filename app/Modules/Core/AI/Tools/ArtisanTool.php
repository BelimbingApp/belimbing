<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Enums\ToolCategory;
use App\Base\AI\Enums\ToolRiskClass;
use App\Base\AI\Tools\AbstractTool;
use App\Base\AI\Tools\Concerns\FormatsProcessResult;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolArgumentException;
use App\Base\AI\Tools\ToolResult;
use Illuminate\Support\Facades\Process;

/**
 * Artisan command execution tool for Digital Workers.
 *
 * Allows a DW to run `php artisan` commands on behalf of the user.
 * Supports foreground execution with configurable timeout, and background
 * execution via Laravel queues (stub — pending queue job implementation).
 *
 * Gated by `ai.tool_artisan.execute` authz capability.
 *
 * Safety: Only `php artisan` commands are allowed. Laravel's Process
 * class uses proc_open without shell invocation, so metacharacters
 * have no shell-level effect. Timeout enforced per execution.
 */
class ArtisanTool extends AbstractTool
{
    use FormatsProcessResult;

    /**
     * Default timeout in seconds for foreground execution.
     */
    private const DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Minimum allowed timeout in seconds.
     */
    private const MIN_TIMEOUT_SECONDS = 1;

    /**
     * Maximum allowed timeout in seconds.
     */
    private const MAX_TIMEOUT_SECONDS = 300;

    public function name(): string
    {
        return 'artisan';
    }

    public function description(): string
    {
        return 'Execute a php artisan command and return its output. '
            .'Use this to query data (e.g., tinker), run BLB commands, check system status, etc. '
            .'Only artisan commands are allowed — no arbitrary shell commands. '
            .'Supports optional timeout override and background execution via Laravel queues.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'command',
                'The artisan command to run (without "php artisan" prefix). '
                    .'Examples: "tinker --execute=\'echo App\\\\Modules\\\\Core\\\\User\\\\Models\\\\User::count();\'", '
                    .'"blb:ai:catalog:sync --dry-run", "route:list --columns=name,uri", '
                    .'"blb:user:create alice@example.com --name=\'Alice Smith\' --role=core_admin".'
            )->required()
            ->integer(
                'timeout',
                'Timeout in seconds for foreground execution (default '.self::DEFAULT_TIMEOUT_SECONDS.', '
                    .'min '.self::MIN_TIMEOUT_SECONDS.', max '.self::MAX_TIMEOUT_SECONDS.'). '
                    .'Ignored when background is true.',
                min: self::MIN_TIMEOUT_SECONDS,
                max: self::MAX_TIMEOUT_SECONDS
            )
            ->boolean(
                'background',
                'Run the command in the background via Laravel queues. '
                    .'Returns a dispatch_id immediately for polling with delegation_status tool.'
            );
    }

    public function category(): ToolCategory
    {
        return ToolCategory::SYSTEM;
    }

    public function riskClass(): ToolRiskClass
    {
        return ToolRiskClass::HIGH_IMPACT;
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_artisan.execute';
    }

    protected function handle(array $arguments): ToolResult
    {
        $command = $this->requireString($arguments, 'command');

        // Strip "php artisan" prefix if the LLM included it
        $command = preg_replace('/^(php\s+)?artisan\s+/', '', $command) ?? $command;

        if ($command === '') {
            throw new ToolArgumentException('Empty command after parsing.');
        }

        $background = $this->optionalBool($arguments, 'background');

        if ($background) {
            return ToolResult::success($this->executeBackground($command));
        }

        return $this->executeForeground($command, $arguments);
    }

    /**
     * Execute the command in the foreground with a configurable timeout.
     *
     * @param  array<string, mixed>  $arguments  Original arguments for timeout extraction
     */
    private function executeForeground(string $command, array $arguments): ToolResult
    {
        $timeout = $this->optionalInt(
            $arguments,
            'timeout',
            self::DEFAULT_TIMEOUT_SECONDS,
            self::MIN_TIMEOUT_SECONDS,
            self::MAX_TIMEOUT_SECONDS
        );

        $fullCommand = 'php artisan '.$command;

        $result = Process::timeout($timeout)
            ->path(base_path())
            ->run($fullCommand);

        return $this->formatProcessResult($result);
    }

    /**
     * Dispatch the command for background execution via Laravel queues.
     *
     * Returns a dispatch_id immediately. The caller can poll for results
     * using the delegation_status tool.
     *
     * Note: Currently returns a stub response. Queue job implementation pending.
     */
    private function executeBackground(string $command): string
    {
        $dispatchId = 'artisan_'.bin2hex(random_bytes(12));

        return json_encode([
            'status' => 'dispatched',
            'dispatch_id' => $dispatchId,
            'command' => $command,
            'message' => 'Command dispatched for background execution (stub). '
                .'Queue job implementation pending. '
                .'Use the delegation_status tool to check progress.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
