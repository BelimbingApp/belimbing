<?php

namespace App\Modules\Core\AI\Console\Commands;

use App\Modules\Core\AI\Enums\RunEventType;
use App\Modules\Core\AI\Models\AiRunEvent;
use App\Modules\Core\AI\Services\AgentToolRegistry;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Summarize agent tool usage from the durable run-event stream.
 *
 * Turns "do we have enough tools?" into data: per-tool call volume,
 * failure and denial rates, unknown-tool attempts (the model reaching for
 * a verb that does not exist — a direct signal of a missing tool), and
 * registered tools that are never used (schema bloat candidates).
 */
#[AsCommand(name: 'blb:ai:tools:stats')]
class ToolStatsCommand extends Command
{
    protected $description = 'Summarize AI tool usage: call volume, failures, denials, unknown-tool attempts, unused tools';

    protected $signature = 'blb:ai:tools:stats
        {--days=30 : Look-back window in days}';

    public function handle(AgentToolRegistry $registry): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $stats = [];
        $unknownAttempts = [];
        $missingCodes = ['unknown_tool', 'tool_not_available'];

        AiRunEvent::query()
            ->whereIn('event_type', [RunEventType::ToolFinished->value, RunEventType::ToolDenied->value])
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->chunk(500, function ($events) use (&$stats, &$unknownAttempts, $missingCodes): void {
                foreach ($events as $event) {
                    $payload = is_array($event->payload) ? $event->payload : [];
                    $tool = (string) ($payload['tool'] ?? '');

                    if ($tool === '') {
                        continue;
                    }

                    $row = &$stats[$tool];
                    $row ??= ['calls' => 0, 'errors' => 0, 'denials' => 0, 'duration_ms' => 0, 'timed' => 0];

                    if ($event->event_type === RunEventType::ToolDenied) {
                        $row['calls']++;
                        $row['denials']++;

                        continue;
                    }

                    $row['calls']++;

                    if (($payload['status'] ?? 'success') === 'error') {
                        $row['errors']++;

                        $code = $payload['error_payload']['code'] ?? null;
                        if (in_array($code, $missingCodes, true)) {
                            $unknownAttempts[$tool] = ($unknownAttempts[$tool] ?? 0) + 1;
                        }
                    }

                    if (isset($payload['duration_ms']) && is_numeric($payload['duration_ms'])) {
                        $row['duration_ms'] += (int) $payload['duration_ms'];
                        $row['timed']++;
                    }
                }
            });

        if ($stats === []) {
            $this->components->info("No tool activity recorded in the last {$days} day(s).");

            return self::SUCCESS;
        }

        uasort($stats, fn (array $a, array $b): int => $b['calls'] <=> $a['calls']);

        $this->components->info("Tool usage over the last {$days} day(s)");

        $this->table(
            ['Tool', 'Calls', 'Errors', 'Denied', 'Error %', 'Avg ms'],
            array_map(
                fn (string $tool, array $row): array => [
                    $tool,
                    (string) $row['calls'],
                    (string) $row['errors'],
                    (string) $row['denials'],
                    $row['calls'] > 0 ? number_format($row['errors'] / $row['calls'] * 100, 1) : '0.0',
                    $row['timed'] > 0 ? (string) (int) ($row['duration_ms'] / $row['timed']) : '-',
                ],
                array_keys($stats),
                array_values($stats),
            ),
        );

        if ($unknownAttempts !== []) {
            arsort($unknownAttempts);
            $this->components->warn('Model reached for tools that were missing or unavailable — candidate gaps:');

            foreach ($unknownAttempts as $tool => $count) {
                $this->components->twoColumnDetail($tool, "{$count} attempt(s)");
            }
        }

        $unused = array_values(array_diff($registry->registeredToolNames(), array_keys($stats)));

        if ($unused !== []) {
            sort($unused);
            $this->components->warn(count($unused).' registered tool(s) saw no use in the window (schema-size candidates):');
            $this->line('  '.implode(', ', $unused));
        }

        return self::SUCCESS;
    }
}
