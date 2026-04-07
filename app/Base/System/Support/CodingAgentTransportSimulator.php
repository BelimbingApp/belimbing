<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System\Support;

use App\Modules\Core\AI\Enums\TurnEventType;
use App\Modules\Core\AI\Enums\TurnPhase;

class CodingAgentTransportSimulator
{
    public const EVENTS_PER_TURN = 10;

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

    /**
     * @return array<string, mixed>
     */
    public function makeFeedPayload(int $sequence, ?string &$activeTool): array
    {
        if ($sequence === 1) {
            return [
                'sequence' => $sequence,
                'event_type' => TurnEventType::TurnStarted->value,
                'message' => __('The simulated coding-agent turn started and is now working.'),
            ];
        }

        if ($activeTool === null) {
            return match (random_int(1, 3)) {
                1 => [
                    'sequence' => $sequence,
                    'event_type' => TurnEventType::TurnPhaseChanged->value,
                    'phase' => TurnPhase::Thinking->value,
                    'message' => self::PHASE_LABELS[array_rand(self::PHASE_LABELS)],
                ],
                2 => $this->startToolPayload($sequence, $activeTool),
                default => [
                    'sequence' => $sequence,
                    'event_type' => TurnEventType::AssistantOutputDelta->value,
                    'message' => self::ASSISTANT_OUTPUT_MESSAGES[array_rand(self::ASSISTANT_OUTPUT_MESSAGES)],
                ],
            };
        }

        return match (random_int(1, 3)) {
            1 => [
                'sequence' => $sequence,
                'event_type' => TurnEventType::ToolStdoutDelta->value,
                'tool_name' => $activeTool,
                'message' => self::TOOL_STDOUT_MESSAGES[array_rand(self::TOOL_STDOUT_MESSAGES)],
            ],
            2 => $this->finishToolPayload($sequence, $activeTool),
            default => [
                'sequence' => $sequence,
                'event_type' => TurnEventType::AssistantOutputDelta->value,
                'message' => self::ASSISTANT_OUTPUT_MESSAGES[array_rand(self::ASSISTANT_OUTPUT_MESSAGES)],
            ],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function makeTurnBurstPayloads(int $turnCount): array
    {
        $payloads = [];
        $sequence = 0;

        foreach (range(1, $turnCount) as $turnNumber) {
            $toolName = self::TOOL_NAMES[($turnNumber - 1) % count(self::TOOL_NAMES)];
            $stdoutMessage = self::TOOL_STDOUT_MESSAGES[($turnNumber - 1) % count(self::TOOL_STDOUT_MESSAGES)];
            $assistantIntro = self::ASSISTANT_OUTPUT_MESSAGES[($turnNumber - 1) % count(self::ASSISTANT_OUTPUT_MESSAGES)];
            $assistantOutro = self::ASSISTANT_OUTPUT_MESSAGES[$turnNumber % count(self::ASSISTANT_OUTPUT_MESSAGES)];

            $payloads[] = [
                'sequence' => ++$sequence,
                'turn_number' => $turnNumber,
                'event_type' => TurnEventType::TurnStarted->value,
                'message' => __('Simulated coding-agent turn :turn started.', ['turn' => $turnNumber]),
            ];
            $payloads[] = [
                'sequence' => ++$sequence,
                'turn_number' => $turnNumber,
                'event_type' => TurnEventType::TurnPhaseChanged->value,
                'phase' => TurnPhase::Thinking->value,
                'message' => self::PHASE_LABELS[($turnNumber - 1) % count(self::PHASE_LABELS)],
            ];
            $payloads[] = [
                'sequence' => ++$sequence,
                'turn_number' => $turnNumber,
                'event_type' => TurnEventType::AssistantOutputDelta->value,
                'message' => $assistantIntro,
            ];
            $payloads[] = [
                'sequence' => ++$sequence,
                'turn_number' => $turnNumber,
                'event_type' => TurnEventType::ToolStarted->value,
                'tool_name' => $toolName,
                'message' => __('Started tool :tool for turn :turn.', ['tool' => $toolName, 'turn' => $turnNumber]),
            ];
            $payloads[] = [
                'sequence' => ++$sequence,
                'turn_number' => $turnNumber,
                'event_type' => TurnEventType::ToolStdoutDelta->value,
                'tool_name' => $toolName,
                'message' => $stdoutMessage,
            ];
            $payloads[] = [
                'sequence' => ++$sequence,
                'turn_number' => $turnNumber,
                'event_type' => TurnEventType::ToolFinished->value,
                'tool_name' => $toolName,
                'message' => __('Finished tool :tool and returned control to the agent.', ['tool' => $toolName]),
            ];
            $payloads[] = [
                'sequence' => ++$sequence,
                'turn_number' => $turnNumber,
                'event_type' => TurnEventType::TurnPhaseChanged->value,
                'phase' => TurnPhase::StreamingAnswer->value,
                'message' => __('Writing the visible response for turn :turn.', ['turn' => $turnNumber]),
            ];
            $payloads[] = [
                'sequence' => ++$sequence,
                'turn_number' => $turnNumber,
                'event_type' => TurnEventType::AssistantOutputDelta->value,
                'message' => $assistantOutro,
            ];
            $payloads[] = [
                'sequence' => ++$sequence,
                'turn_number' => $turnNumber,
                'event_type' => TurnEventType::TurnPhaseChanged->value,
                'phase' => TurnPhase::Finalizing->value,
                'message' => __('Finalizing turn :turn before handing control back.', ['turn' => $turnNumber]),
            ];
            $payloads[] = [
                'sequence' => ++$sequence,
                'turn_number' => $turnNumber,
                'event_type' => TurnEventType::TurnCompleted->value,
                'message' => __('Simulated coding-agent turn :turn completed successfully.', ['turn' => $turnNumber]),
            ];
        }

        return $payloads;
    }

    /**
     * @return array<string, mixed>
     */
    private function startToolPayload(int $sequence, ?string &$activeTool): array
    {
        $activeTool = self::TOOL_NAMES[array_rand(self::TOOL_NAMES)];

        return [
            'sequence' => $sequence,
            'event_type' => TurnEventType::ToolStarted->value,
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
            'event_type' => TurnEventType::ToolFinished->value,
            'tool_name' => $finishedTool,
            'message' => __('Finished tool :tool and returned control to the agent.', ['tool' => $finishedTool]),
        ];
    }
}
