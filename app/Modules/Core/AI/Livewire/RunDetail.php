<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Standalone run detail page - deep-linkable view of a single AI run.

namespace App\Modules\Core\AI\Livewire;

use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;
use App\Modules\Core\AI\Livewire\Concerns\ManagesWireLogWindow;
use App\Modules\Core\AI\Services\ControlPlane\RunDiagnosticService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RunDetail extends Component
{
    use ManagesWireLogWindow;

    public string $runId;

    public function mount(string $runId): void
    {
        $this->runId = $runId;
        $this->resetWireLogWindow();

        $runView = app(RunDiagnosticService::class)->buildRunView(
            $runId,
            wireLogOffset: $this->wireLogOffset,
            wireLogLimit: $this->wireLogLimit,
        );

        if ($runView === null) {
            throw new NotFoundHttpException(__('Run not found.'));
        }

    }

    public function render(): View
    {
        $runView = app(RunDiagnosticService::class)->buildRunView(
            $this->runId,
            wireLogOffset: $this->wireLogOffset,
            wireLogLimit: $this->wireLogLimit,
        );

        if ($runView === null) {
            throw new NotFoundHttpException(__('Run not found.'));
        }

        return view('livewire.admin.ai.run-detail', [
            'runView' => [
                'inspection' => $this->mapRunInspection($runView['inspection']),
                'transcript' => $runView['transcript'],
                'triggering_prompt' => $runView['triggering_prompt'],
                'wire_log_entries' => $runView['wire_log_entries'],
                'wire_log_summary' => $runView['wire_log_summary'],
                'wire_logging_enabled' => $runView['wire_logging_enabled'],
                'turn_id' => $runView['turn_id'],
            ],
            'operationsBreadcrumb' => $this->operationsBreadcrumb(),
        ]);
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
}
