<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\DTO\ControlPlane\HealthSnapshot;
use App\Modules\Core\AI\DTO\ControlPlane\LifecyclePreview;
use App\Modules\Core\AI\DTO\ControlPlane\LifecycleRequest as LifecycleRequestDTO;
use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;
use App\Modules\Core\AI\DTO\Message;

trait MapsControlPlaneState
{
    private function resolveTab(string $tab): string
    {
        return in_array($tab, ['inspector', 'turns', 'health', 'lifecycle'], true)
            ? $tab
            : 'inspector';
    }

    /**
     * @return array{label: string, url: string|null}|null
     */
    private function operationsBreadcrumb(): ?array
    {
        if ((string) request()->query('from') !== 'operations') {
            return null;
        }

        $returnTo = (string) request()->query('returnTo', '');

        return [
            'label' => __('AI / Operations'),
            'url' => str_starts_with($returnTo, '/') ? $returnTo : null,
        ];
    }

    /**
     * @param  array{
     *     inspection: RunInspection,
     *     transcript: list<Message>,
     *     triggering_prompt: Message|null,
     *     wire_log_entries: list<array{
     *         at: string|null,
     *         type: string|null,
     *         payload_pretty: string,
     *         payload_truncated: bool,
     *         preview_status: string,
     *         raw_line: string,
     *         decoded_payload: array<string, mixed>|null
     *     }>,
     *     wire_log_readable: array<string, mixed>,
     *     wire_log_summary: array{
     *         footprint_bytes: int,
     *         total_entries: int,
     *         visible_entries: int,
     *         offset: int,
     *         limit: int,
     *         range_start: int,
     *         range_end: int,
     *         omitted_before: int,
     *         omitted_after: int,
     *         has_previous: bool,
     *         has_next: bool,
     *         last_offset: int
     *     },
     *     wire_logging_enabled: bool,
     *     turn_id: string|null
     * }|null  $runView
     * @return array<string, mixed>|null
     */
    private function mapRunView(?array $runView): ?array
    {
        if ($runView === null) {
            return null;
        }

        return [
            'inspection' => $this->mapRunInspection($runView['inspection']),
            'transcript' => $runView['transcript'],
            'triggering_prompt' => $runView['triggering_prompt'],
            'wire_log_entries' => $runView['wire_log_entries'],
            'wire_log_readable' => $runView['wire_log_readable'],
            'wire_log_summary' => $runView['wire_log_summary'],
            'wire_logging_enabled' => $runView['wire_logging_enabled'],
            'turn_id' => $runView['turn_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRunInspection(RunInspection $run): array
    {
        $data = $run->toArray();
        $status = $run->status;

        $data['status_label'] = $status?->label();
        $data['status_color'] = $status?->color();
        $data['outcome_label'] = ucfirst((string) $data['outcome']);
        $data['outcome_color'] = match ($data['outcome']) {
            'success' => 'success',
            'error' => 'danger',
            'cancelled' => 'warning',
            default => 'default',
        };

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapHealthSnapshot(HealthSnapshot $snapshot): array
    {
        $data = $snapshot->toArray();

        $data['readiness_label'] = $snapshot->readiness->label();
        $data['readiness_color'] = $snapshot->readiness->color();
        $data['health_label'] = $snapshot->health->label();
        $data['health_color'] = $snapshot->health->color();
        $data['presence_label'] = $snapshot->presence->label();
        $data['presence_color'] = $snapshot->presence->color();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLifecyclePreview(LifecyclePreview $preview): array
    {
        $data = $preview->toArray();
        $data['action_label'] = $preview->action->label();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLifecycleRequest(LifecycleRequestDTO $request): array
    {
        $data = $request->toArray();
        $data['action_label'] = $request->action->label();
        $data['status_label'] = $request->status->label();
        $data['status_color'] = $request->status->color();
        $data['preview'] = $request->preview ? $this->mapLifecyclePreview($request->preview) : null;

        return $data;
    }
}
