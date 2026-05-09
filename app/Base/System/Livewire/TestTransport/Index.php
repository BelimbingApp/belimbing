<?php
namespace App\Base\System\Livewire\TestTransport;

use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Models\AiRun;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public string $selectedTurnId = '';

    public int $speed = 1;

    public function render(): View
    {
        $turns = AiRun::query()
            ->withCount('events')
            ->where('source', 'chat')
            ->whereIn('status', [
                AiRunStatus::Succeeded->value,
                AiRunStatus::Failed->value,
                AiRunStatus::Cancelled->value,
                AiRunStatus::TimedOut->value,
            ])
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['id', 'session_id', 'status', 'current_phase', 'started_at', 'finished_at', 'created_at']);

        return view('livewire.admin.system.test-transport.index', [
            'turns' => $turns,
            'streamUrl' => route('admin.system.test-transport.stream'),
        ]);
    }
}
