<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Tools\Concerns;

use Illuminate\Contracts\Process\ProcessResult;

/**
 * Formats process execution results for tool output.
 *
 * Used by tools that execute system processes (bash, artisan) to produce
 * consistent, LLM-readable output from success and failure cases.
 */
trait FormatsProcessResult
{
    protected function formatProcessResult(ProcessResult $result): string
    {
        $output = trim($result->output());
        $errorOutput = trim($result->errorOutput());

        if (! $result->successful()) {
            $message = 'Command failed (exit code '.$result->exitCode().').';

            if ($errorOutput !== '') {
                $message .= "\n".$errorOutput;
            }

            if ($output !== '') {
                $message .= "\n".$output;
            }

            return $message;
        }

        if ($output === '' && $errorOutput === '') {
            return 'Command completed successfully (no output).';
        }

        return $output !== '' ? $output : $errorOutput;
    }
}
