<?php

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Contracts\ProvidesDisplaySummary;
use App\Base\AI\Contracts\StreamableTool;
use App\Base\AI\Exceptions\ShellBackendUnavailableException;
use App\Base\AI\Services\ShellCommandRunner;
use App\Base\AI\Tools\AbstractHighImpactProcessTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;

/**
 * Bash CLI execution tool for Agents.
 *
 * Allows an agent to run arbitrary bash commands on behalf of the user.
 * This is the most powerful tool — gated by `admin.ai.tool.bash.execute`.
 *
 * Implements StreamableTool to yield incremental stdout/stderr as the
 * command runs, enabling real-time output in the agent console UI.
 *
 * Safety: two independent gates must both pass. The `ai.tools.bash.enabled`
 * config flag (default OFF in production) is a hard kill-switch so a prompt
 * injection cannot become RCE on a live deployment, and the
 * `admin.ai.tool.bash.execute` capability restricts which users may trigger it.
 * A per-execution timeout bounds runtime.
 */
class BashTool extends AbstractHighImpactProcessTool implements ProvidesDisplaySummary, StreamableTool
{
    private const TIMEOUT_SECONDS = 30;

    private const STREAM_POLL_INTERVAL_US = 100_000; // 100ms

    private const MAX_STDOUT_EVENTS = 50;

    public function __construct(
        private readonly ?ShellCommandRunner $shell = null,
    ) {}

    public function name(): string
    {
        return 'bash';
    }

    public function description(): string
    {
        return 'Execute a shell command from the repository core surface and return its output. '
            .'Commands are killed after '.self::TIMEOUT_SECONDS.'s, so scope test runs to one file or filter - full suites will not finish. '
            .'For PHP/Eloquent snippets, write the code to a file first and run `php artisan tinker <file.php>`; '
            .'inline `tinker --execute` one-liners with namespaces break under Windows shell quoting. '
            .'For read-only SQL prefer the query_data tool.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'command',
                'The shell command to execute.'
            )->required();
    }

    public function requiredCapability(): ?string
    {
        return 'admin.ai.tool.bash.execute';
    }

    public function displaySummary(array $arguments): string
    {
        $command = is_string($arguments['command'] ?? null) ? trim($arguments['command']) : '';

        return $command !== '' ? '$ '.$command : __('Run shell command');
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Shell',
            'summary' => 'Execute shell commands on the server.',
            'explanation' => 'Runs shell commands through the configured Belimbing shell backend. Extremely powerful — can modify files, '
                .'install packages, and interact with the operating system. '
                .'Requires the highest authorization level.',
            'test_examples' => [
                [
                    'label' => 'Git status',
                    'input' => ['command' => 'git status --short'],
                ],
                [
                    'label' => '⚠ Clear application logs (irreversible)',
                    'input' => ['command' => 'php -r "file_put_contents(\'storage/logs/laravel.log\', \'\'); echo \'Log cleared.\';"'],
                    'runnable' => false,
                ],
            ],
            'health_checks' => [
                'Shell access available',
            ],
            'limits' => [
                'Full server access — authorize carefully',
            ],
        ];
    }

    protected function handle(array $arguments): ToolResult
    {
        if (($disabled = $this->disabledResult()) !== null) {
            return $disabled;
        }

        $command = $this->requireString($arguments, 'command');

        try {
            $result = $this->shell()->run($command, base_path(), self::TIMEOUT_SECONDS);
        } catch (ShellBackendUnavailableException $e) {
            return ToolResult::error($e->getMessage(), 'command_unavailable');
        }

        return $this->formatProcessResult($result);
    }

    /**
     * Execute with incremental stdout streaming via proc_open.
     *
     * Yields output chunks as they become available, enabling the agent
     * console to display real-time command output. Falls back to the
     * synchronous path if proc_open fails.
     *
     * @return \Generator<int, string, mixed, ToolResult>
     */
    public function executeStreaming(array $arguments): \Generator
    {
        if (($disabled = $this->disabledResult()) !== null) {
            return $disabled;
        }

        $command = $this->requireString($arguments, 'command');

        $opened = $this->openBashStreamingPipes($command);
        if ($opened === null) {
            return ToolResult::error('Failed to start bash process.', 'command_failed');
        }

        [$process, $pipes] = $opened;

        return yield from $this->yieldBashStreamUntilComplete(
            $process,
            $pipes,
        );
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}|null
     */
    private function openBashStreamingPipes(string $command): ?array
    {
        try {
            $opened = $this->shell()->openStreamingProcess($command, base_path());
        } catch (ShellBackendUnavailableException) {
            return null;
        }

        return $opened;
    }

    /**
     * @param  array<int, resource>  $pipes
     * @return \Generator<int, string, mixed, ToolResult>
     */
    private function yieldBashStreamUntilComplete(mixed $process, array $pipes): \Generator
    {
        $stdout = '';
        $stderr = '';
        $eventCount = 0;
        $startTime = hrtime(true);
        $timeoutNs = self::TIMEOUT_SECONDS * 1_000_000_000;

        while (true) {
            $stdoutChunk = (string) stream_get_contents($pipes[1]);
            $stderrChunk = (string) stream_get_contents($pipes[2]);
            $stdout .= $stdoutChunk;
            $stderr .= $stderrChunk;
            $combined = $stdoutChunk.$stderrChunk;

            if ($combined !== '' && $eventCount < self::MAX_STDOUT_EVENTS) {
                yield $combined;
                $eventCount++;
            }

            $status = proc_get_status($process);

            if (! $status['running']) {
                yield from $this->yieldFinalBashDrain($pipes, $stdout, $stderr, $eventCount);

                return $this->finalizeBashProcessResult($process, $pipes, $status, $stdout, $stderr);
            }

            if ((hrtime(true) - $startTime) > $timeoutNs) {
                return $this->terminateBashForTimeout($process, $pipes, $stdout);
            }

            usleep(self::STREAM_POLL_INTERVAL_US);
        }
    }

    /**
     * @param  array<int, resource>  $pipes
     * @return \Generator<int, string>
     */
    private function yieldFinalBashDrain(array $pipes, string &$stdout, string &$stderr, int $eventCount): \Generator
    {
        $finalStdout = (string) stream_get_contents($pipes[1]);
        $finalStderr = (string) stream_get_contents($pipes[2]);
        $stdout .= $finalStdout;
        $stderr .= $finalStderr;

        if (($finalStdout.$finalStderr) !== '' && $eventCount < self::MAX_STDOUT_EVENTS) {
            yield $finalStdout.$finalStderr;
        }
    }

    /**
     * @param  array<int, resource>  $pipes
     */
    private function terminateBashForTimeout(mixed $process, array $pipes, string $stdout): ToolResult
    {
        proc_terminate($process, 9);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return ToolResult::error(
            'Command timed out after '.self::TIMEOUT_SECONDS.' seconds.'
            .($stdout !== '' ? "\nPartial output:\n".$stdout : ''),
            'command_timeout',
        );
    }

    /**
     * @param  array<int, resource>  $pipes
     */
    private function finalizeBashProcessResult(
        mixed $process,
        array $pipes,
        array $status,
        string $stdout,
        string $stderr,
    ): ToolResult {
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = $status['exitcode'] ?? proc_close($process);

        if (is_resource($process)) {
            proc_close($process);
        }

        $stdout = trim($stdout);
        $stderr = trim($stderr);

        if ($exitCode !== 0) {
            $message = 'Command failed (exit code '.$exitCode.').';
            if ($stderr !== '') {
                $message .= "\n".$stderr;
            }
            if ($stdout !== '') {
                $message .= "\n".$stdout;
            }

            return ToolResult::error($message, 'command_failed');
        }

        if ($stdout === '' && $stderr === '') {
            return ToolResult::success('Command completed successfully (no output).');
        }

        return ToolResult::success($stdout !== '' ? $stdout : $stderr);
    }

    /**
     * Hard kill-switch, independent of authz. When the bash tool is disabled
     * (default in production) no command runs, whatever capability the actor
     * holds. Returns an unavailable result to surface in both the sync and
     * streaming paths, or null when execution may proceed.
     */
    private function disabledResult(): ?ToolResult
    {
        if ((bool) config('ai.tools.bash.enabled', false)) {
            return null;
        }

        return ToolResult::unavailable(
            'bash_disabled',
            'The shell tool is disabled on this environment.',
            'Set AI_BASH_TOOL_ENABLED=true to enable it — ideally only alongside an OS-level sandbox for the shell backend.',
        );
    }

    private function shell(): ShellCommandRunner
    {
        return $this->shell ?? app(ShellCommandRunner::class);
    }
}
