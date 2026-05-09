<?php
namespace App\Base\Workflow\Console\Commands;

use App\Base\Workflow\Models\StatusConfig;
use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Validate graph integrity for a workflow: orphans, unreachable nodes, missing capabilities.
 */
#[AsCommand(name: 'blb:workflow:validate')]
class WorkflowValidateCommand extends Command
{
    protected $signature = 'blb:workflow:validate
                            {flow : Flow identifier to validate}';

    protected $description = 'Check workflow graph integrity: orphans, unreachable nodes, dangling edges';

    public function handle(): int
    {
        $flow = $this->argument('flow');

        $statuses = StatusConfig::query()
            ->forFlow($flow)
            ->active()
            ->pluck('code')
            ->all();

        if (empty($statuses)) {
            $this->components->error("No active statuses found for flow '{$flow}'.");

            return Command::FAILURE;
        }

        $transitions = StatusTransition::query()
            ->forFlow($flow)
            ->active()
            ->get();

        $statusSet = array_flip($statuses);
        $sourceCodes = $transitions->pluck('from_code')->unique()->all();
        $targetCodes = $transitions->pluck('to_code')->unique()->all();
        $allReferenced = array_unique(array_merge($sourceCodes, $targetCodes));

        $issues = [
            ...$this->validateEdgeReferences($statusSet, $transitions),
            ...$this->validateOrphanStatuses($statuses, $allReferenced),
            ...$this->validateBoundaryStatuses($statuses, $sourceCodes, $targetCodes),
            ...$this->validateClassReferences($transitions),
        ];

        if (empty($issues)) {
            $startCandidates = array_diff($statuses, $targetCodes);
            $terminalCandidates = array_diff($statuses, $sourceCodes);

            $this->components->info("Flow '{$flow}' is valid.");
            $this->components->twoColumnDetail('Statuses', (string) count($statuses));
            $this->components->twoColumnDetail('Transitions', (string) $transitions->count());
            $this->components->twoColumnDetail('Start candidates', implode(', ', $startCandidates) ?: '—');
            $this->components->twoColumnDetail('Terminal candidates', implode(', ', $terminalCandidates) ?: '—');

            return Command::SUCCESS;
        }

        $this->components->error("Flow '{$flow}' has ".count($issues).' issue(s):');
        $this->line('');

        foreach ($issues as $issue) {
            $this->components->bulletList([$issue]);
        }

        return Command::FAILURE;
    }

    /**
     * Check that every transition's from/to codes exist in the active status set.
     *
     * @param  array<string, int>  $statusSet  Flipped status code map for O(1) lookup
     * @param  Collection<int, StatusTransition>  $transitions
     * @return array<int, string>
     */
    private function validateEdgeReferences(array $statusSet, Collection $transitions): array
    {
        $issues = [];

        foreach ($transitions as $transition) {
            if (! isset($statusSet[$transition->from_code])) {
                $issues[] = "Transition references non-existent source status: '{$transition->from_code}' → '{$transition->to_code}'";
            }

            if (! isset($statusSet[$transition->to_code])) {
                $issues[] = "Transition references non-existent target status: '{$transition->from_code}' → '{$transition->to_code}'";
            }
        }

        return $issues;
    }

    /**
     * Detect statuses that have neither inbound nor outbound transitions.
     *
     * @param  array<int, string>  $statuses
     * @param  array<int, string>  $allReferenced  Union of all from/to codes
     * @return array<int, string>
     */
    private function validateOrphanStatuses(array $statuses, array $allReferenced): array
    {
        $issues = [];

        foreach ($statuses as $code) {
            if (! in_array($code, $allReferenced, true)) {
                $issues[] = "Orphan status: '{$code}' has no inbound or outbound transitions";
            }
        }

        return $issues;
    }

    /**
     * Verify the graph has at least one start status and one terminal status.
     *
     * @param  array<int, string>  $statuses
     * @param  array<int, string>  $sourceCodes  All from_code values
     * @param  array<int, string>  $targetCodes  All to_code values
     * @return array<int, string>
     */
    private function validateBoundaryStatuses(array $statuses, array $sourceCodes, array $targetCodes): array
    {
        $issues = [];
        $startCandidates = array_diff($statuses, $targetCodes);
        $terminalCandidates = array_diff($statuses, $sourceCodes);

        if (empty($startCandidates) && ! empty($statuses)) {
            $issues[] = 'No start status detected (all statuses have inbound transitions — possible cycle without entry point)';
        }

        if (empty($terminalCandidates) && ! empty($statuses)) {
            $issues[] = 'No terminal status detected (all statuses have outbound transitions — possible infinite loop)';
        }

        return $issues;
    }

    /**
     * Check that guard_class and action_class references on transitions resolve to existing classes.
     *
     * @param  Collection<int, StatusTransition>  $transitions
     * @return array<int, string>
     */
    private function validateClassReferences(Collection $transitions): array
    {
        $issues = [];

        foreach ($transitions as $transition) {
            if ($transition->guard_class !== null && ! class_exists($transition->guard_class)) {
                $issues[] = "Guard class not found: '{$transition->guard_class}' on '{$transition->from_code}' → '{$transition->to_code}'";
            }

            if ($transition->action_class !== null && ! class_exists($transition->action_class)) {
                $issues[] = "Action class not found: '{$transition->action_class}' on '{$transition->from_code}' → '{$transition->to_code}'";
            }
        }

        return $issues;
    }
}
