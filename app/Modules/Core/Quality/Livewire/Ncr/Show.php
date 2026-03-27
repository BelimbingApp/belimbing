<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Livewire\Ncr;

use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Quality\Models\Ncr;
use App\Modules\Core\Quality\Services\EvidenceService;
use App\Modules\Core\Quality\Services\NcrService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class Show extends Component
{
    use WithFileUploads;

    public Ncr $ncr;

    public string $transitionComment = '';

    public $evidenceFile = null;

    public string $evidenceType = 'original_complaint';

    // Triage fields
    public ?string $triageSummary = null;

    public ?string $triageSeverity = null;

    public ?string $triageClassification = null;

    // Assign fields
    public ?string $assignDepartment = null;

    // Submit Response fields
    public ?string $containmentAction = null;

    public ?string $rootCauseOccurred = null;

    public ?string $rootCauseLeakage = null;

    public ?string $correctiveActionOccurred = null;

    public ?string $correctiveActionLeakage = null;

    // Review fields
    public ?string $reviewComment = null;

    public ?string $reworkReason = null;

    // Verify field
    public string $verificationResult = 'effective';

    public function mount(Ncr $ncr): void
    {
        $this->ncr = $ncr->load('createdByUser', 'currentOwner', 'capa', 'scars', 'evidence');
    }

    public function uploadEvidence(EvidenceService $evidenceService): void
    {
        $this->validate([
            'evidenceFile' => ['required', 'file', 'max:10240'],
            'evidenceType' => ['required', Rule::in(array_keys(config('quality.evidence_types')))],
        ]);

        $evidenceService->upload(
            $this->ncr,
            $this->evidenceFile,
            $this->evidenceType,
            Auth::id(),
        );

        $this->evidenceFile = null;
        $this->ncr->load('evidence');
        Session::flash('success', __('Evidence uploaded successfully.'));
    }

    public function deleteEvidence(int $evidenceId, EvidenceService $evidenceService): void
    {
        $evidence = $this->ncr->evidence->find($evidenceId);

        if ($evidence) {
            $evidenceService->archive($evidence);
            $this->ncr->load('evidence');
            Session::flash('success', __('Evidence removed.'));
        }
    }

    public function transitionTo(string $toCode, NcrService $ncrService): void
    {
        $user = Auth::user();
        $actor = Actor::forUser($user);

        $data = ['comment' => $this->transitionComment ?: null];

        $result = match ($toCode) {
            'under_triage' => $ncrService->triage($this->ncr, $actor, [
                ...$data,
                'triage_summary' => $this->triageSummary,
                'severity' => $this->triageSeverity,
                'classification' => $this->triageClassification,
            ]),
            'assigned' => $ncrService->assign($this->ncr, $actor, [
                ...$data,
                'current_owner_department' => $this->assignDepartment,
            ]),
            'in_progress' => $this->ncr->status === 'under_review'
                ? $ncrService->review($this->ncr, $actor, [
                    ...$data,
                    'approved' => false,
                    'quality_review_comment' => $this->reviewComment,
                    'rework_reason' => $this->reworkReason,
                ])
                : $ncrService->startInvestigation($this->ncr, $actor, $data),
            'under_review' => $ncrService->submitResponse($this->ncr, $actor, [
                ...$data,
                'containment_action' => $this->containmentAction,
                'root_cause_occurred' => $this->rootCauseOccurred,
                'root_cause_leakage' => $this->rootCauseLeakage,
                'corrective_action_occurred' => $this->correctiveActionOccurred,
                'corrective_action_leakage' => $this->correctiveActionLeakage,
            ]),
            'verified' => $ncrService->review($this->ncr, $actor, [
                ...$data,
                'approved' => true,
                'quality_review_comment' => $this->reviewComment,
            ]),
            'closed' => $ncrService->close($this->ncr, $actor, $data),
            'rejected' => $ncrService->reject($this->ncr, $actor, [
                ...$data,
                'reject_reason' => $this->transitionComment ?: __('Rejected'),
            ]),
            default => null,
        };

        if ($result === null) {
            Session::flash('error', __('Unknown transition target.'));

            return;
        }

        if ($result->success) {
            $this->resetTransitionFields();
            $this->ncr->refresh();
            $this->ncr->load('createdByUser', 'currentOwner', 'capa', 'scars', 'evidence');
            Session::flash('success', __('NCR transitioned successfully.'));
        } else {
            Session::flash('error', $result->reason ?? __('Transition failed.'));
        }
    }

    private function resetTransitionFields(): void
    {
        $this->transitionComment = '';
        $this->triageSummary = null;
        $this->triageSeverity = null;
        $this->triageClassification = null;
        $this->assignDepartment = null;
        $this->containmentAction = null;
        $this->rootCauseOccurred = null;
        $this->rootCauseLeakage = null;
        $this->correctiveActionOccurred = null;
        $this->correctiveActionLeakage = null;
        $this->reviewComment = null;
        $this->reworkReason = null;
        $this->verificationResult = 'effective';
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
