<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Livewire\Scar;

use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Quality\Models\Scar;
use App\Modules\Core\Quality\Services\ScarService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Component;

class Show extends Component
{
    public Scar $scar;

    public string $transitionComment = '';

    public function mount(Scar $scar): void
    {
        $this->scar = $scar->load('ncr', 'issueOwner', 'verifiedByUser', 'closedByUser', 'evidence');
    }

    public function transitionTo(string $toCode, ScarService $scarService): void
    {
        $user = Auth::user();
        $actor = Actor::forUser($user);

        $data = ['comment' => $this->transitionComment ?: null];

        $methodMap = [
            'issued' => 'issue',
            'acknowledged' => 'acknowledge',
            'containment_submitted' => 'submitContainment',
            'response_submitted' => 'submitResponse',
            'under_review' => 'review',
            'verification_pending' => 'review',
            'action_required' => 'review',
            'closed' => 'close',
        ];

        $method = $methodMap[$toCode] ?? null;

        if ($method === null) {
            Session::flash('error', __('Unknown transition target.'));

            return;
        }

        if ($method === 'submitContainment') {
            $data['containment_response'] = $this->transitionComment ?: __('Containment submitted');
        }

        if ($method === 'review' && $toCode === 'verification_pending') {
            $data['accepted'] = true;
        } elseif ($method === 'review' && $toCode === 'action_required') {
            $data['accepted'] = false;
        }

        $result = $scarService->$method($this->scar, $actor, $data);

        if ($result->success) {
            $this->transitionComment = '';
            $this->scar->refresh();
            $this->scar->load('ncr', 'issueOwner', 'verifiedByUser', 'closedByUser', 'evidence');
            Session::flash('success', __('SCAR transitioned successfully.'));
        } else {
            Session::flash('error', $result->reason ?? __('Transition failed.'));
        }
    }

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'draft' => 'default',
            'issued' => 'info',
            'acknowledged' => 'accent',
            'containment_submitted' => 'accent',
            'under_investigation' => 'warning',
            'response_submitted' => 'accent',
            'under_review' => 'accent',
            'action_required' => 'warning',
            'verification_pending' => 'info',
            'closed' => 'default',
            'rejected' => 'danger',
            'cancelled' => 'default',
            default => 'default',
        };
    }

    public function render(): View
    {
        return view('livewire.quality.scar.show', [
            'timeline' => $this->scar->statusTimeline(),
            'availableTransitions' => $this->scar->availableTransitions(),
        ]);
    }
}
