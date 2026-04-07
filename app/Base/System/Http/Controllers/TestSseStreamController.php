<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TestSseStreamController
{
    public const DEFAULT_RUNTIME_SECONDS = 600;

    public const DEFAULT_MIN_FEED_INTERVAL_SECONDS = 5;

    public const DEFAULT_MAX_FEED_INTERVAL_SECONDS = 180;

    private const FEED_EVENT_NAME = 'agent-feed';

    private const COMPLETE_EVENT_NAME = 'complete';

    private const KEEPALIVE_SECONDS = 15;

    private const LOOP_INTERVAL_MICROSECONDS = 250000;

    private const RETRY_MS = 1000;

    /**
     * @var list<string>
     */
    private const TOOL_NAMES = [
        'rg',
        'view',
        'bash',
        'glob',
        'web_fetch',
    ];

    /**
     * @var list<string>
     */
    private const TOOL_STDOUT_MESSAGES = [
        'Searching the codebase for matching files.',
        'Reading the relevant module to confirm behavior.',
        'Running a narrow command to confirm the current state.',
        'Collecting surrounding context before making the next decision.',
        'Parsing the latest output from the running command.',
    ];

    /**
     * @var list<string>
     */
    private const ASSISTANT_OUTPUT_MESSAGES = [
        'Summarizing the current findings before taking the next action.',
        'Explaining the change that will be made next.',
        'Describing the tradeoff between the available implementation paths.',
        'Preparing the next visible update for the operator.',
        'Writing the next incremental response chunk.',
    ];

    /**
     * @var list<string>
     */
    private const PHASE_LABELS = [
        'Inspecting the surrounding code paths.',
        'Reviewing the latest command output.',
        'Planning the next tool step.',
        'Applying a focused change to the implementation.',
        'Validating the updated behavior.',
    ];

    public function __invoke(Request $request): StreamedResponse
    {
        return response()->stream(function () use ($request): void {
            set_time_limit(0);

            $runtimeSeconds = $this->runtimeSeconds($request);
            $minFeedIntervalSeconds = $this->minFeedIntervalSeconds($request);
            $maxFeedIntervalSeconds = $this->maxFeedIntervalSeconds($request);
            $streamEndsAt = microtime(true) + $runtimeSeconds;
            $nextFeedAt = microtime(true);
            $lastKeepAliveAt = microtime(true);
            $feedSequence = 0;
            $activeTool = null;

            echo ': TestSSE stream opened for HTTP/2 EventSource clients'."\n";
            echo 'retry: '.self::RETRY_MS."\n\n";
            $this->flushOutput();

            $this->writeEvent(null, [
                'connection' => 'sse',
                'transport' => 'http2',
                'message' => __('Connected to the TestSSE coding-agent simulation. The stream stays open for :duration seconds and emits live feed updates at random intervals between :min and :max seconds.', [
                    'duration' => $runtimeSeconds,
                    'min' => $minFeedIntervalSeconds,
                    'max' => $maxFeedIntervalSeconds,
                ]),
                'user_id' => (int) $request->user()->getAuthIdentifier(),
                'runtime_seconds' => $runtimeSeconds,
                'min_feed_interval_seconds' => $minFeedIntervalSeconds,
                'max_feed_interval_seconds' => $maxFeedIntervalSeconds,
                'sent_at' => now()->toIso8601String(),
            ]);

            while (microtime(true) < $streamEndsAt) {
                if (connection_aborted()) {
                    return;
                }

                $now = microtime(true);

                if ($now >= $nextFeedAt) {
                    $feedSequence++;
                    $payload = $this->makeAgentFeedPayload($feedSequence, $activeTool);

                    $this->writeEvent(self::FEED_EVENT_NAME, [
                        ...$payload,
                        'connection' => 'sse',
                        'transport' => 'http2',
                        'sent_at' => now()->toIso8601String(),
                        'seconds_remaining' => max(0, (int) ceil($streamEndsAt - $now)),
                    ]);

                    $nextFeedAt = $now + random_int($minFeedIntervalSeconds, $maxFeedIntervalSeconds);
                    $lastKeepAliveAt = $now;

                    continue;
                }

                if (($now - $lastKeepAliveAt) >= self::KEEPALIVE_SECONDS) {
                    $this->writeComment('keepalive '.now()->toIso8601String());
                    $lastKeepAliveAt = $now;
                }

                usleep(self::LOOP_INTERVAL_MICROSECONDS);
            }

            if (! connection_aborted()) {
                $this->writeEvent(self::COMPLETE_EVENT_NAME, [
                    'connection' => 'sse',
                    'transport' => 'http2',
                    'event_type' => 'turn.completed',
                    'sequence' => $feedSequence + 1,
                    'sent_at' => now()->toIso8601String(),
                    'message' => __('The 10-minute TestSSE coding-agent simulation reached its time limit and closed normally.'),
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeEvent(?string $event, array $payload): void
    {
        if ($event !== null) {
            echo 'event: '.$event."\n";
        }

        echo 'data: '.$this->encodePayload($payload)."\n\n";

        $this->flushOutput();
    }

    private function writeComment(string $comment): void
    {
        echo ': '.$comment."\n\n";

        $this->flushOutput();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeAgentFeedPayload(int $sequence, ?string &$activeTool): array
    {
        if ($sequence === 1) {
            return [
                'sequence' => $sequence,
                'event_type' => 'turn.started',
                'message' => __('The simulated coding-agent turn started and is now working.'),
            ];
        }

        if ($activeTool === null) {
            return match (random_int(1, 3)) {
                1 => [
                    'sequence' => $sequence,
                    'event_type' => 'turn.phase_changed',
                    'phase' => 'thinking',
                    'message' => self::PHASE_LABELS[array_rand(self::PHASE_LABELS)],
                ],
                2 => $this->startToolPayload($sequence, $activeTool),
                default => [
                    'sequence' => $sequence,
                    'event_type' => 'assistant.output_delta',
                    'message' => self::ASSISTANT_OUTPUT_MESSAGES[array_rand(self::ASSISTANT_OUTPUT_MESSAGES)],
                ],
            };
        }

        return match (random_int(1, 3)) {
            1 => [
                'sequence' => $sequence,
                'event_type' => 'tool.stdout_delta',
                'tool_name' => $activeTool,
                'message' => self::TOOL_STDOUT_MESSAGES[array_rand(self::TOOL_STDOUT_MESSAGES)],
            ],
            2 => $this->finishToolPayload($sequence, $activeTool),
            default => [
                'sequence' => $sequence,
                'event_type' => 'assistant.output_delta',
                'message' => self::ASSISTANT_OUTPUT_MESSAGES[array_rand(self::ASSISTANT_OUTPUT_MESSAGES)],
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function startToolPayload(int $sequence, ?string &$activeTool): array
    {
        $activeTool = self::TOOL_NAMES[array_rand(self::TOOL_NAMES)];

        return [
            'sequence' => $sequence,
            'event_type' => 'tool.started',
            'tool_name' => $activeTool,
            'message' => __('Started tool :tool.', ['tool' => $activeTool]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finishToolPayload(int $sequence, ?string &$activeTool): array
    {
        $finishedTool = $activeTool;
        $activeTool = null;

        return [
            'sequence' => $sequence,
            'event_type' => 'tool.finished',
            'tool_name' => $finishedTool,
            'message' => __('Finished tool :tool and returned control to the agent.', ['tool' => $finishedTool]),
        ];
    }

    private function runtimeSeconds(Request $request): int
    {
        if (! app()->runningUnitTests()) {
            return self::DEFAULT_RUNTIME_SECONDS;
        }

        return max(1, (int) $request->integer('duration_seconds', self::DEFAULT_RUNTIME_SECONDS));
    }

    private function minFeedIntervalSeconds(Request $request): int
    {
        if (! app()->runningUnitTests()) {
            return self::DEFAULT_MIN_FEED_INTERVAL_SECONDS;
        }

        return max(0, (int) $request->integer('min_interval_seconds', self::DEFAULT_MIN_FEED_INTERVAL_SECONDS));
    }

    private function maxFeedIntervalSeconds(Request $request): int
    {
        if (! app()->runningUnitTests()) {
            return self::DEFAULT_MAX_FEED_INTERVAL_SECONDS;
        }

        $minIntervalSeconds = $this->minFeedIntervalSeconds($request);
        $maxIntervalSeconds = (int) $request->integer('max_interval_seconds', self::DEFAULT_MAX_FEED_INTERVAL_SECONDS);

        return max($minIntervalSeconds, $maxIntervalSeconds);
    }

    private function flushOutput(): void
    {
        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
