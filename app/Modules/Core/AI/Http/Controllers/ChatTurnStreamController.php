<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Services\ChatTurnRunner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatTurnStreamController
{
    public function __invoke(Request $request, string $turnId): StreamedResponse
    {
        $turn = ChatTurn::query()->find($turnId);

        if ($turn === null) {
            return $this->errorStream(404, 'Turn not found');
        }

        if ((int) $turn->acting_for_user_id !== (int) auth()->id()) {
            return $this->errorStream(403, 'Forbidden');
        }

        if ($turn->status !== TurnStatus::Queued) {
            return $this->errorStream(409, 'Turn is not in queued state');
        }

        return response()->stream(function () use ($turn): void {
            set_time_limit(0);

            $runner = app(ChatTurnRunner::class);
            $disconnected = false;

            try {
                $runner->run($turn, function (array $payload) use ($turn, &$disconnected): void {
                    if ($disconnected || connection_aborted()) {
                        if (! $disconnected) {
                            $turn->requestCancel('Client disconnected');
                            $disconnected = true;
                        }

                        return;
                    }

                    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                    $this->flushOutput();
                });
            } catch (\Throwable $e) {
                if (!$disconnected && !connection_aborted()) {
                    echo json_encode([
                        'error' => $e->getMessage(),
                        '_stream_complete' => true,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
                    $this->flushOutput();
                }

                return;
            }

            if (!$disconnected && !connection_aborted()) {
                echo json_encode(['_stream_complete' => true], JSON_THROW_ON_ERROR) . "\n";
                $this->flushOutput();
            }
        }, 200, $this->streamHeaders());
    }

    private function errorStream(int $status, string $message): StreamedResponse
    {
        return response()->stream(function () use ($message): void {
            echo json_encode(['error' => $message], JSON_THROW_ON_ERROR) . "\n";
            $this->flushOutput();
        }, $status, $this->streamHeaders());
    }

    /**
     * @return array<string, string>
     */
    private function streamHeaders(): array
    {
        return [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
