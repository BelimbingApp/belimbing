<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Tools;

use App\Base\AI\Tools\AbstractHighImpactProcessTool;
use App\Base\AI\Tools\Schema\ToolSchemaBuilder;
use App\Base\AI\Tools\ToolResult;
use Illuminate\Support\Facades\Process;

/**
 * Bash CLI execution tool for Agents.
 *
 * Allows a agent to run arbitrary bash commands on behalf of the user.
 * This is the most powerful tool — gated by `ai.tool_bash.execute`.
 *
 * Safety: Timeout enforced per execution. Authz gating is the primary
 * control — only users with explicit bash capability can trigger this.
 */
class BashTool extends AbstractHighImpactProcessTool
{
    private const TIMEOUT_SECONDS = 30;

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
}
