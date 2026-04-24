<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WireLogEntryController
{
    public function __invoke(string $runId, int $entryNumber): StreamedResponse
    {
        $wireLogger = app(WireLogger::class);

        if (! is_file($wireLogger->path($runId)) || ! $wireLogger->hasEntry($runId, $entryNumber)) {
            abort(404);
        }

        return response()->stream(function () use ($wireLogger, $runId, $entryNumber): void {
            $wireLogger->streamRawEntry($runId, $entryNumber, function (string $chunk): void {
                echo $chunk;
            });

            echo "\n";
        }, 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.$runId.'-wire-log-entry-'.$entryNumber.'.json"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
