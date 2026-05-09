<?php
namespace App\Modules\Core\AI\Services;

use App\Base\Foundation\Enums\BlbErrorCode;
use App\Base\Foundation\Exceptions\BlbDataContractException;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;

class LaraCapabilityMatcher
{
    /**
     * Return Agents the current authenticated user can delegate to.
     *
     * @return list<array{employee_id: int, name: string, capability_summary: string}>
     */
    public function discoverDelegableAgentsForCurrentUser(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return $user->getAgents()
            ->map(fn (Employee $employee): array => [
                'employee_id' => $this->employeeId($employee),
                'name' => $employee->displayName(),
                'capability_summary' => $this->capabilitySummary($employee),
            ])
            ->values()
            ->all();
    }

    /**
     * Find a specific accessible Agent by ID.
     *
     * @return array{employee_id: int, name: string, capability_summary: string}|null
     */
    public function findAccessibleAgentById(int $employeeId): ?array
    {
        foreach ($this->discoverDelegableAgentsForCurrentUser() as $agent) {
            if ($agent['employee_id'] === $employeeId) {
                return $agent;
            }
        }

        return null;
    }

    /**
     * Match the best available Agent for a free-text task.
     *
     * @return array{employee_id: int, name: string, capability_summary: string, match_score: int}|null
     */
    public function matchBestForTask(string $task): ?array
    {
        $agents = $this->discoverDelegableAgentsForCurrentUser();

        if ($agents === []) {
            return null;
        }

        $best = null;
        $bestScore = -1;

        foreach ($agents as $agent) {
            $score = $this->scoreTask($task, $agent['capability_summary']);

            if ($score > $bestScore) {
                $best = $agent;
                $bestScore = $score;
            }
        }

        if ($best === null) {
            return null;
        }

        return [
            ...$best,
            'match_score' => max($bestScore, 0),
        ];
    }

    /**
     * Build a concise capability summary for a Agent.
     */
    private function capabilitySummary(Employee $employee): string
    {
        $designation = trim((string) ($employee->designation ?? ''));
        $description = trim((string) ($employee->job_description ?? ''));

        if ($designation === '' && $description === '') {
            return __('General Agent');
        }

        if ($designation !== '' && $description !== '') {
            return $designation.' — '.$description;
        }

        return $designation !== '' ? $designation : $description;
    }

    /**
     * Score task relevance based on simple keyword overlap.
     */
    private function scoreTask(string $task, string $summary): int
    {
        $normalizedTask = strtolower((string) preg_replace('/[^a-z0-9\s]/i', ' ', $task));
        $normalizedSummary = strtolower($summary);

        $keywords = array_filter(
            array_unique(explode(' ', $normalizedTask)),
            fn (string $keyword): bool => strlen($keyword) >= 3,
        );

        $score = 0;
        foreach ($keywords as $keyword) {
            if (str_contains($normalizedSummary, $keyword)) {
                $score++;
            }
        }

        return $score;
    }

    private function employeeId(Employee $employee): int
    {
        if (! is_int($employee->id)) {
            throw new BlbDataContractException(
                'Invalid Agent identifier type.',
                BlbErrorCode::LARA_AGENT_ID_TYPE_INVALID,
                ['employee_id' => $employee->id]
            );
        }

        return $employee->id;
    }
}
