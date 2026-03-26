<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Livewire\Ncr;

use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Quality\Models\Ncr;
use App\Modules\Core\Quality\Services\NcrService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class Show extends Component
{
    public Ncr $ncr;

    public string $transitionComment = '';

    public function mount(Ncr $ncr): void
    {
        $this->ncr = $ncr->load('createdByUser', 'currentOwner', 'capa', 'scars', 'evidence');
    }

    public function transitionTo(string $toCode, NcrService $ncrService): void
    {
        $user = Auth::user();
        $actor = Actor::forUser($user);

        $methodMap = [
            'under_triage' => 'triage',
            'assigned' => 'assign',
            'in_progress' => 'submitResponse',
            'under_review' => 'submitResponse',
            'verified' => 'verify',
            'closed' => 'close',
            'rejected' => 'reject',
        ];

        $method = $methodMap[$toCode] ?? null;

        if ($method === null) {
            Session::flash('error', __('Unknown transition target.'));

            return;
        }

        $data = ['comment' => $this->transitionComment ?: null];

        if ($toCode === 'rejected') {
            $data['reject_reason'] = $this->transitionComment ?: __('Rejected');
        }

        if ($toCode === 'verified') {
            $data['verification_result'] = 'effective';
        }

        if ($method === 'review') {
            $data['approved'] = true;
        }

        $result = $ncrService->$method($this->ncr, $actor, $data);

        if ($result->success) {
            $this->transitionComment = '';
            $this->ncr->refresh();
            $this->ncr->load('createdByUser', 'currentOwner', 'capa', 'scars', 'evidence');
            Session::flash('success', __('NCR transitioned successfully.'));
        } else {
            Session::flash('error', $result->reason ?? __('Transition failed.'));
        }
    }

    public function severityVariant(string $severity): string
    {
        return match ($severity) {
            'critical' => 'danger',
            'major' => 'warning',
            'minor' => 'info',
            'observation' => 'default',
            default => 'default',
        };
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'open' => 'info',
            'under_triage' => 'accent',
            'assigned' => 'accent',
            'in_progress' => 'warning',
            'under_review' => 'accent',
            'verified' => 'success',
            'closed' => 'default',
            'rejected' => 'danger',
            default => 'default',
        };
    }

    public function render(): View
    {
        return view('livewire.quality.ncr.show', [
            'timeline' => $this->ncr->statusTimeline(),
            'availableTransitions' => $this->ncr->availableTransitions(),
        ]);
    }
}
