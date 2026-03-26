<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Services;

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionResult;
use App\Base\Workflow\Models\StatusHistory;
use App\Modules\Core\Quality\Contracts\NumberingService;
use App\Modules\Core\Quality\Models\Capa;
use App\Modules\Core\Quality\Models\Ncr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Domain service for NCR operations.
 *
 * Centralizes NCR lifecycle commands so that Livewire pages, API
 * endpoints, and extensions share the same mutation logic.
 */
class NcrService
{
    public function __construct(
        private readonly NumberingService $numbering,
    ) {}

    /**
     * Open a new NCR with its initial CAPA record and status history.
     *
     * @param  Actor  $actor  The principal performing the action
     * @param  array{company_id: int, ncr_kind: string, title: string, reported_by_name: string, source?: string|null, severity?: string|null, classification?: string|null, summary?: string|null, product_name?: string|null, product_code?: string|null, quantity_affected?: string|null, uom?: string|null, reported_at?: Carbon|null, reported_by_email?: string|null, is_supplier_related?: bool, metadata?: array<string, mixed>|null}  $data
     */
    public function open(Actor $actor, array $data): Ncr
    {
        return DB::transaction(function () use ($actor, $data): Ncr {
            $ncr = Ncr::query()->create([
                'company_id' => $data['company_id'],
                'ncr_no' => $this->numbering->nextNcrNumber($data['ncr_kind']),
                'ncr_kind' => $data['ncr_kind'],
                'source' => $data['source'] ?? null,
                'status' => 'open',
                'severity' => $data['severity'] ?? null,
                'classification' => $data['classification'] ?? null,
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'product_name' => $data['product_name'] ?? null,
                'product_code' => $data['product_code'] ?? null,
                'quantity_affected' => $data['quantity_affected'] ?? null,
                'uom' => $data['uom'] ?? null,
                'reported_at' => $data['reported_at'] ?? Carbon::now(),
                'reported_by_name' => $data['reported_by_name'],
                'reported_by_email' => $data['reported_by_email'] ?? null,
                'created_by_user_id' => $actor->id,
                'is_supplier_related' => $data['is_supplier_related'] ?? false,
                'metadata' => $data['metadata'] ?? null,
            ]);

            Capa::query()->create([
                'ncr_id' => $ncr->id,
                'workflow_status' => 'triage_pending',
            ]);

            StatusHistory::query()->create([
                'flow' => 'quality_ncr',
                'flow_id' => $ncr->id,
                'status' => 'open',
                'actor_id' => $actor->id,
                'actor_role' => $actor->attributes['role'] ?? null,
                'actor_department' => $actor->attributes['department'] ?? null,
                'actor_company' => $actor->attributes['company'] ?? null,
                'comment' => $data['summary'] ?? null,
                'comment_tag' => 'report',
                'metadata' => [
                    'ncr_kind' => $data['ncr_kind'],
                    'severity' => $data['severity'] ?? null,
                ],
                'transitioned_at' => Carbon::now(),
            ]);

            return $ncr;
        });
    }

    /**
     * Triage an NCR: transition to under_triage and record triage findings on the CAPA.
     *
     * @param  Ncr  $ncr  The NCR to triage
     * @param  Actor  $actor  The principal performing triage
     * @param  array{triage_summary?: string|null, triage_confidence?: string|null, severity?: string|null, classification?: string|null, comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function triage(Ncr $ncr, Actor $actor, array $data = []): TransitionResult
    {
        return DB::transaction(function () use ($ncr, $actor, $data): TransitionResult {
            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: 'triage',
                metadata: $data['metadata'] ?? null,
            );

            $result = $ncr->transitionTo('under_triage', $context);

            if (! $result->success) {
                return $result;
            }

            $updates = array_filter([
                'triage_summary' => $data['triage_summary'] ?? null,
                'triage_confidence' => $data['triage_confidence'] ?? null,
                'workflow_status' => 'triaged',
            ], fn ($v) => $v !== null);

            $ncr->capa?->update($updates);

            if (isset($data['severity'])) {
                $ncr->update(['severity' => $data['severity']]);
            }

            if (isset($data['classification'])) {
                $ncr->update(['classification' => $data['classification']]);
            }

            return $result;
        });
    }

    /**
     * Assign an NCR to a department/user for investigation.
     *
     * @param  Ncr  $ncr  The NCR to assign
     * @param  Actor  $actor  The principal performing assignment
     * @param  array{current_owner_user_id?: int|null, current_owner_department?: string|null, assigned_department?: string|null, assigned_supplier_name?: string|null, assignment_comment?: string|null, assignment_due_at?: Carbon|null, comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function assign(Ncr $ncr, Actor $actor, array $data = []): TransitionResult
    {
        return DB::transaction(function () use ($ncr, $actor, $data): TransitionResult {
            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: 'assignment',
                metadata: $data['metadata'] ?? null,
            );

            $result = $ncr->transitionTo('assigned', $context);

            if (! $result->success) {
                return $result;
            }

            $now = Carbon::now();

            $ncr->update([
                'current_owner_user_id' => $data['current_owner_user_id'] ?? null,
                'current_owner_department' => $data['current_owner_department'] ?? null,
                'current_owner_assigned_at' => $now,
            ]);

            $ncr->capa?->update(array_filter([
                'assigned_department' => $data['assigned_department'] ?? $data['current_owner_department'] ?? null,
                'assigned_supplier_name' => $data['assigned_supplier_name'] ?? null,
                'assignment_comment' => $data['assignment_comment'] ?? null,
                'assignment_due_at' => $data['assignment_due_at'] ?? null,
                'assigned_by_user_id' => $actor->id,
                'assigned_at' => $now,
                'workflow_status' => 'assigned',
            ], fn ($v) => $v !== null));

            return $result;
        });
    }

    /**
     * Submit a response (investigation findings) and transition to in_progress → under_review.
     *
     * The assignee submits containment, root cause, and corrective action data.
     *
     * @param  Ncr  $ncr  The NCR with investigation response
     * @param  Actor  $actor  The principal submitting the response
     * @param  array{containment_action?: string|null, correction?: string|null, root_cause_occurred?: string|null, root_cause_leakage?: string|null, corrective_action_occurred?: string|null, effective_date_occurred?: Carbon|string|null, corrective_action_leakage?: string|null, effective_date_leakage?: Carbon|string|null, comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function submitResponse(Ncr $ncr, Actor $actor, array $data = []): TransitionResult
    {
        return DB::transaction(function () use ($ncr, $actor, $data): TransitionResult {
            if ($ncr->status === 'assigned') {
                $startContext = new TransitionContext(actor: $actor);
                $startResult = $ncr->transitionTo('in_progress', $startContext);

                if (! $startResult->success) {
                    return $startResult;
                }

                $ncr->refresh();
            }

            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: 'response',
                metadata: $data['metadata'] ?? null,
            );

            $result = $ncr->transitionTo('under_review', $context);

            if (! $result->success) {
                return $result;
            }

            $capaUpdates = array_filter([
                'containment_action' => $data['containment_action'] ?? null,
                'correction' => $data['correction'] ?? null,
                'root_cause_occurred' => $data['root_cause_occurred'] ?? null,
                'root_cause_leakage' => $data['root_cause_leakage'] ?? null,
                'corrective_action_occurred' => $data['corrective_action_occurred'] ?? null,
                'effective_date_occurred' => $data['effective_date_occurred'] ?? null,
                'corrective_action_leakage' => $data['corrective_action_leakage'] ?? null,
                'effective_date_leakage' => $data['effective_date_leakage'] ?? null,
                'workflow_status' => 'response_submitted',
            ], fn ($v) => $v !== null);

            $ncr->capa?->update($capaUpdates);

            return $result;
        });
    }

    /**
     * Review an NCR response: approve and advance, or request rework.
     *
     * @param  Ncr  $ncr  The NCR under review
     * @param  Actor  $actor  The quality reviewer
     * @param  array{approved: bool, quality_review_comment?: string|null, quality_feedback?: string|null, rework_reason?: string|null, comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function review(Ncr $ncr, Actor $actor, array $data): TransitionResult
    {
        return DB::transaction(function () use ($ncr, $actor, $data): TransitionResult {
            $approved = $data['approved'];
            $toCode = $approved ? 'verified' : 'in_progress';
            $commentTag = $approved ? 'review_approved' : 'review_rework';

            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: $commentTag,
                metadata: $data['metadata'] ?? null,
            );

            $result = $ncr->transitionTo($toCode, $context);

            if (! $result->success) {
                return $result;
            }

            if ($approved) {
                $ncr->capa?->update(array_filter([
                    'quality_review_comment' => $data['quality_review_comment'] ?? null,
                    'quality_feedback' => $data['quality_feedback'] ?? null,
                    'approval_state' => 'approved',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => Carbon::now(),
                    'workflow_status' => 'verified',
                ], fn ($v) => $v !== null));
            } else {
                $ncr->capa?->update(array_filter([
                    'quality_review_comment' => $data['quality_review_comment'] ?? null,
                    'quality_feedback' => $data['quality_feedback'] ?? null,
                    'rework_reason' => $data['rework_reason'] ?? null,
                    'approval_state' => 'returned',
                    'workflow_status' => 'rework_required',
                ], fn ($v) => $v !== null));
            }

            return $result;
        });
    }

    /**
     * Verify an NCR: confirm effectiveness of corrective actions.
     *
     * @param  Ncr  $ncr  The NCR to verify
     * @param  Actor  $actor  The verifier
     * @param  array{verification_result: string, comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function verify(Ncr $ncr, Actor $actor, array $data): TransitionResult
    {
        return DB::transaction(function () use ($ncr, $actor, $data): TransitionResult {
            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: 'verification',
                metadata: $data['metadata'] ?? null,
            );

            $result = $ncr->transitionTo('verified', $context);

            if (! $result->success) {
                return $result;
            }

            $ncr->capa?->update([
                'verification_result' => $data['verification_result'],
                'verified_by_user_id' => $actor->id,
                'verified_at' => Carbon::now(),
                'workflow_status' => 'verified',
            ]);

            return $result;
        });
    }

    /**
     * Close an NCR.
     *
     * @param  Ncr  $ncr  The NCR to close
     * @param  Actor  $actor  The principal closing the NCR
     * @param  array{comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function close(Ncr $ncr, Actor $actor, array $data = []): TransitionResult
    {
        return DB::transaction(function () use ($ncr, $actor, $data): TransitionResult {
            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: 'close',
                metadata: $data['metadata'] ?? null,
            );

            $result = $ncr->transitionTo('closed', $context);

            if (! $result->success) {
                return $result;
            }

            $ncr->capa?->update([
                'closed_by_user_id' => $actor->id,
                'closed_at' => Carbon::now(),
                'workflow_status' => 'closed',
            ]);

            return $result;
        });
    }

    /**
     * Reject an NCR as invalid or not justified.
     *
     * @param  Ncr  $ncr  The NCR to reject
     * @param  Actor  $actor  The principal rejecting the NCR
     * @param  array{reject_reason: string, comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function reject(Ncr $ncr, Actor $actor, array $data): TransitionResult
    {
        return DB::transaction(function () use ($ncr, $actor, $data): TransitionResult {
            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? $data['reject_reason'],
                commentTag: 'rejection',
                metadata: $data['metadata'] ?? null,
            );

            $result = $ncr->transitionTo('rejected', $context);

            if (! $result->success) {
                return $result;
            }

            $ncr->update([
                'reject_reason' => $data['reject_reason'],
                'rejected_at' => Carbon::now(),
            ]);

            $ncr->capa?->update([
                'approval_state' => 'not_justified',
                'workflow_status' => 'rejected',
            ]);

            return $result;
        });
    }
}
