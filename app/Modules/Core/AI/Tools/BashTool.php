<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Contracts\StreamableTool;
use App\Base\AI\Tools\AbstractHighImpactProcessTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use Illuminate\Support\Facades\Process;

/**
 * Bash CLI execution tool for Agents.
 *
 * Allows an agent to run arbitrary bash commands on behalf of the user.
 * This is the most powerful tool — gated by `ai.tool_bash.execute`.
 *
 * Implements StreamableTool to yield incremental stdout/stderr as the
 * command runs, enabling real-time output in the agent console UI.
 *
 * Safety: Timeout enforced per execution. Authz gating is the primary
 * control — only users with explicit bash capability can trigger this.
 */
class BashTool extends AbstractHighImpactProcessTool implements StreamableTool
{
    private const TIMEOUT_SECONDS = 30;

    private const STREAM_POLL_INTERVAL_US = 100_000; // 100ms

    private const MAX_STDOUT_EVENTS = 50;

    public function name(): string
    {
        return 'bash';
    }

    public function description(): string
    {
        return 'Execute a bash command and return its output. '
            .'Use this for system commands, file operations, package management, git, etc. '
            .'Commands run from the BLB project root directory.';
    }

    protected function schema(): ToolSchemaBuilder
    {
        return ToolSchemaBuilder::make()
            ->string(
                'command',
                'The bash command to execute. '
                    .'Examples: "ls -la storage/app", "cat .env | grep DB_", "git log --oneline -5".'
            )->required();
    }

    public function requiredCapability(): ?string
    {
        return 'ai.tool_bash.execute';
    }

    protected function metadata(): array
    {
        return [
            'display_name' => 'Bash',
            'summary' => 'Execute shell commands on the server.',
            'explanation' => 'Runs shell commands on the BLB server. Extremely powerful — can modify files, '
                .'install packages, and interact with the operating system. '
                .'Requires the highest authorization level.',
            'test_examples' => [
                [
                    'label' => 'Disk usage',
                    'input' => ['command' => 'df -h'],
                ],
                [
                    'label' => '⚠ Clear application logs (irreversible)',
                    'input' => ['command' => 'truncate -s 0 storage/logs/laravel.log && echo "Log cleared."'],
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
        $command = $this->requireString($arguments, 'command');

        $result = Process::timeout(self::TIMEOUT_SECONDS)
            ->path(base_path())
            ->run($command);

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
        $command = $this->requireString($arguments, 'command');

        $descriptors = [
            0 => ['pipe', 'r'],       // stdin
            1 => ['pipe', 'w'],       // stdout
            2 => ['pipe', 'w'],       // stderr
        ];

        $process = proc_open(
            ['bash', '-c', $command],
            $descriptors,
            $pipes,
            base_path(),
        );

        if (! is_resource($process)) {
            return ToolResult::error('Failed to start bash process.', 'command_failed');
        }

        fclose($pipes[0]); // close stdin

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

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
                // Drain remaining output
                $finalStdout = (string) stream_get_contents($pipes[1]);
                $finalStderr = (string) stream_get_contents($pipes[2]);
                $stdout .= $finalStdout;
                $stderr .= $finalStderr;

                if (($finalStdout.$finalStderr) !== '' && $eventCount < self::MAX_STDOUT_EVENTS) {
                    yield $finalStdout.$finalStderr;
                }

                break;
            }

            if ((hrtime(true) - $startTime) > $timeoutNs) {
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

            usleep(self::STREAM_POLL_INTERVAL_US);
        }

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
}
