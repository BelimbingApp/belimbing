<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Livewire\Scar;

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionResult;
use App\Modules\Core\Quality\Models\Scar;
use App\Modules\Core\Quality\Services\EvidenceService;
use App\Modules\Core\Quality\Services\ScarService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class Show extends Component
{
    use WithFileUploads;

    public Scar $scar;

    public string $transitionComment = '';

    public $evidenceFile = null;

    public string $evidenceType = 'supplier_response';

    // Response fields
    public ?string $containmentResponse = null;

    public ?string $rootCauseResponse = null;

    public ?string $correctiveActionResponse = null;

    public function mount(Scar $scar): void
    {
        $this->scar = $scar->load('ncr', 'issueOwner', 'verifiedByUser', 'closedByUser', 'evidence');
    }

    public function uploadEvidence(EvidenceService $evidenceService): void
    {
        $this->validate([
            'evidenceFile' => ['required', 'file', 'max:10240'],
            'evidenceType' => ['required', Rule::in(array_keys(config('quality.evidence_types')))],
        ]);

        $evidenceService->upload(
            $this->scar,
            $this->evidenceFile,
            $this->evidenceType,
            Auth::id(),
        );

        $this->evidenceFile = null;
        $this->scar->load('evidence');
        Session::flash('success', __('Evidence uploaded successfully.'));
    }

    public function deleteEvidence(int $evidenceId, EvidenceService $evidenceService): void
    {
        $evidence = $this->scar->evidence->find($evidenceId);

        if ($evidence) {
            $evidenceService->archive($evidence);
            $this->scar->load('evidence');
            Session::flash('success', __('Evidence removed.'));
        }
    }

    public function transitionTo(string $toCode, ScarService $scarService): void
    {
        $user = Auth::user();
        $actor = Actor::forUser($user);

        $data = ['comment' => $this->transitionComment ?: null];

        $result = match ($toCode) {
            'issued' => $scarService->issue($this->scar, $actor, $data),
            'acknowledged' => $scarService->acknowledge($this->scar, $actor, $data),
            'containment_submitted' => $scarService->submitContainment($this->scar, $actor, [
                ...$data,
                'containment_response' => $this->containmentResponse ?: $this->transitionComment ?: __('Containment submitted'),
            ]),
            'under_investigation' => $scarService->submitResponse($this->scar, $actor, [
                ...$data,
                'root_cause_response' => $this->rootCauseResponse,
                'corrective_action_response' => $this->correctiveActionResponse,
            ]),
            'response_submitted' => $scarService->submitResponse($this->scar, $actor, [
                ...$data,
                'root_cause_response' => $this->rootCauseResponse,
                'corrective_action_response' => $this->correctiveActionResponse,
            ]),
            'under_review' => $scarService->beginReview($this->scar, $actor, $data),
            'verification_pending' => $scarService->review($this->scar, $actor, [...$data, 'accepted' => true]),
            'action_required' => $scarService->review($this->scar, $actor, [...$data, 'accepted' => false]),
            'closed' => $scarService->verify($this->scar, $actor, $data),
            'cancelled', 'rejected' => $this->handleDirectTransition($toCode, $actor, $data),
            default => null,
        };

        if ($result === null) {
            Session::flash('error', __('Unknown transition target.'));

            return;
        }

        if ($result->success) {
            $this->resetTransitionFields();
            $this->scar->refresh();
            $this->scar->load('ncr', 'issueOwner', 'verifiedByUser', 'closedByUser', 'evidence');
            Session::flash('success', __('SCAR transitioned successfully.'));
        } else {
            Session::flash('error', $result->reason ?? __('Transition failed.'));
        }
    }

    private function handleDirectTransition(string $toCode, Actor $actor, array $data): TransitionResult
    {
        $context = new TransitionContext(
            actor: $actor,
            comment: $data['comment'] ?? null,
            commentTag: $toCode === 'cancelled' ? 'cancellation' : 'rejection',
        );

        return $this->scar->transitionTo($toCode, $context);
    }

    private function resetTransitionFields(): void
    {
        $this->transitionComment = '';
        $this->containmentResponse = null;
        $this->rootCauseResponse = null;
        $this->correctiveActionResponse = null;
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
