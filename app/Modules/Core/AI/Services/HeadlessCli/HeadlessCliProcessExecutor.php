<?php

namespace App\Modules\Core\AI\Services\HeadlessCli;

use Symfony\Component\Process\Process;

class HeadlessCliProcessExecutor
{
    /**
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function run(string $command, int $timeoutSeconds): array
    {
        $process = Process::fromShellCommandline($command, base_path(), null, null, $timeoutSeconds);
        $process->run();

        return [
            'exit_code' => $process->getExitCode() ?? 1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
