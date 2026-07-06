<?php

namespace App\Modules\Core\AI\Livewire\Widgets;

use App\Base\Dashboard\Widget;
use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Services\OperationsDispatchService;
use Illuminate\Contracts\View\View;

/**
 * Dashboard widget: AI operation dispatch ledger counts by status.
 *
 * Visibility is gated by `admin.ai.agent.view` in Config/dashboard.php.
 */
class OperationsStatus extends Widget
{
    public function render(OperationsDispatchService $operations): View
    {
        $counts = $operations->statusCounts();

        return view('livewire.ai.widgets.operations-status', [
            'stats' => [
                ['label' => __('Queued'), 'value' => $counts[OperationStatus::Queued->value] ?? 0],
                ['label' => __('Running'), 'value' => $counts[OperationStatus::Running->value] ?? 0],
                ['label' => __('Failed'), 'value' => $counts[OperationStatus::Failed->value] ?? 0],
                ['label' => __('Succeeded'), 'value' => $counts[OperationStatus::Succeeded->value] ?? 0],
            ],
        ]);
    }
}
