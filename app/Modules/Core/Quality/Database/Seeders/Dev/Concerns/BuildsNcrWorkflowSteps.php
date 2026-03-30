<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Database\Seeders\Dev\Concerns;

use App\Modules\Core\User\Models\User;

trait BuildsNcrWorkflowSteps
{
    /**
     * Build a normalized workflow step definition.
     *
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor?: User, payload: array<string, mixed>}
     */
    private function workflowStep(string $action, array $payload = [], ?User $actor = null): array
    {
        $step = [
            'action' => $action,
            'payload' => $payload,
        ];

        if ($actor instanceof User) {
            $step['actor'] = $actor;
        }

        return $step;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function triageStep(User $actor, array $payload): array
    {
        return $this->workflowStep('triage', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function assignStep(User $actor, array $payload): array
    {
        return $this->workflowStep('assign', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function submitResponseStep(User $actor, array $payload): array
    {
        return $this->workflowStep('submit_response', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function reviewStep(User $actor, array $payload): array
    {
        return $this->workflowStep('review', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function rejectStep(User $actor, array $payload): array
    {
        return $this->workflowStep('reject', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function closeStep(User $actor, array $payload): array
    {
        return $this->workflowStep('close', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, payload: array<string, mixed>}
     */
    private function capaUpdateStep(array $payload): array
    {
        return $this->workflowStep('capa_update', $payload);
    }
}
