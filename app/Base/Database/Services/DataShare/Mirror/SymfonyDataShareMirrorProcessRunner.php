<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorProcessRunner;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorProcessResult;
use App\Base\Database\Exceptions\DataShareMirrorException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SymfonyDataShareMirrorProcessRunner implements DataShareMirrorProcessRunner
{
    public function find(string $executable): ?string
    {
        if (! in_array($executable, ['pg_dump', 'psql'], true)) {
            return null;
        }

        $configured = config('data_share.mirror.executables.'.$executable);
        if (is_string($configured) && is_file($configured)) {
            return $configured;
        }

        $suffix = DIRECTORY_SEPARATOR.$executable.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        $patterns = PHP_OS_FAMILY === 'Linux'
            ? ['/usr/lib/postgresql/*/bin'.$suffix]
            : [];
        $localAppData = getenv('LOCALAPPDATA');
        if (is_string($localAppData) && $localAppData !== '') {
            $patterns[] = $localAppData.DIRECTORY_SEPARATOR.'Belimbing'.DIRECTORY_SEPARATOR.'PostgreSQL'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'pgsql'.DIRECTORY_SEPARATOR.'bin'.$suffix;
        }

        $programFiles = getenv('ProgramFiles');
        if (is_string($programFiles) && $programFiles !== '') {
            $patterns[] = $programFiles.DIRECTORY_SEPARATOR.'PostgreSQL'.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.'bin'.$suffix;
        }

        $candidates = [];
        foreach ($patterns as $pattern) {
            $candidates = array_merge($candidates, glob($pattern) ?: []);
        }

        usort($candidates, static fn (string $left, string $right): int => strnatcasecmp($right, $left));

        return $candidates[0] ?? (new ExecutableFinder)->find($executable);
    }

    public function run(array $command, array $environment = [], int $timeout = 30): DataShareMirrorProcessResult
    {
        $process = new Process($command, null, $environment, null, $timeout);

        try {
            $process->run();
        } catch (ProcessRuntimeException $exception) {
            $tool = $this->safeToolName($command);
            throw $tool === 'pg_dump'
                ? DataShareMirrorException::preMutationProcessFailed($tool, previous: $exception)
                : DataShareMirrorException::processFailed($tool, previous: $exception);
        }

        // Process output is intentionally bounded and returned only to trusted
        // callers such as the version probe. Mirror failures never surface it:
        // PostgreSQL diagnostics may echo a host or user from the connection.
        $output = substr(trim($process->getOutput()."\n".$process->getErrorOutput()), 0, 4096);

        return new DataShareMirrorProcessResult(
            exitCode: $process->getExitCode() ?? 1,
            output: $output,
        );
    }

    /** @param list<string> $command */
    private function safeToolName(array $command): string
    {
        $path = (string) ($command[0] ?? 'PostgreSQL client');
        $name = pathinfo($path, PATHINFO_FILENAME);

        return in_array($name, ['pg_dump', 'psql'], true) ? $name : 'PostgreSQL client';
    }
}
