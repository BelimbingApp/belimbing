<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;

class LaraCapabilityMatcher
{
    /**
     * Return Digital Workers the current authenticated user can delegate to.
     *
     * @return list<array{employee_id: int, name: string, capability_summary: string}>
     */
    public function discoverDelegableWorkersForCurrentUser(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return $user->getDigitalWorkers()
            ->map(fn (Employee $employee): array => [
                'employee_id' => $this->employeeId($employee),
                'name' => $employee->displayName(),
                'capability_summary' => $this->capabilitySummary($employee),
            ])
            ->values()
            ->all();
    }

    /**
     * Find a specific accessible Digital Worker by ID.
     *
     * @return array{employee_id: int, name: string, capability_summary: string}|null
     */
    public function findAccessibleWorkerById(int $employeeId): ?array
    {
        foreach ($this->discoverDelegableWorkersForCurrentUser() as $worker) {
            if ($worker['employee_id'] === $employeeId) {
                return $worker;
            }
        }

        return null;
    }

    /**
     * Match the best available Digital Worker for a free-text task.
     *
     * @return array{employee_id: int, name: string, capability_summary: string, match_score: int}|null
     */
    public function matchBestForTask(string $task): ?array
    {
        $workers = $this->discoverDelegableWorkersForCurrentUser();

        if ($workers === []) {
            return null;
        }

        $best = null;
        $bestScore = -1;

        foreach ($workers as $worker) {
            $score = $this->scoreTask($task, $worker['capability_summary']);

            if ($score > $bestScore) {
                $best = $worker;
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
     * Build a concise capability summary for a Digital Worker.
     */
    private function capabilitySummary(Employee $employee): string
    {
        $designation = trim((string) ($employee->designation ?? ''));
        $description = trim((string) ($employee->job_description ?? ''));

        if ($designation === '' && $description === '') {
            return __('General Digital Worker');
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
            throw new \RuntimeException('Invalid Digital Worker identifier type.');
        }

        return $employee->id;
    }
}
