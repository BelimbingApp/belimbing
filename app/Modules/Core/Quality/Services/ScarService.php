<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Services;

use App\Base\Authz\DTO\Actor;
use App\Base\Workflow\DTO\TransitionContext;
use App\Base\Workflow\DTO\TransitionResult;
use App\Base\Workflow\Models\StatusHistory;
use App\Modules\Core\Quality\Contracts\NumberingService;
use App\Modules\Core\Quality\Models\Ncr;
use App\Modules\Core\Quality\Models\Scar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Domain service for SCAR operations.
 *
 * Centralizes SCAR lifecycle commands so that Livewire pages, API
 * endpoints, and extensions share the same mutation logic.
 */
class ScarService
{
    public function __construct(
        private readonly NumberingService $numbering,
    ) {}

    /**
     * Create a new SCAR in draft status, linked to an NCR.
     *
     * @param  Actor  $actor  The principal creating the SCAR
     * @param  Ncr  $ncr  The parent NCR
     * @param  array{supplier_name: string, supplier_site?: string|null, supplier_contact_name?: string|null, supplier_contact_email?: string|null, supplier_contact_phone?: string|null, po_do_invoice_no?: string|null, product_name?: string|null, product_code?: string|null, detected_area?: string|null, request_type?: string|null, severity?: string|null, claim_quantity?: string|null, uom?: string|null, claim_value?: string|null, problem_description?: string|null, acknowledgement_due_at?: Carbon|null, containment_due_at?: Carbon|null, response_due_at?: Carbon|null, verification_due_at?: Carbon|null, metadata?: array<string, mixed>|null}  $data
     */
    public function create(Actor $actor, Ncr $ncr, array $data): Scar
    {
        return DB::transaction(function () use ($actor, $ncr, $data): Scar {
            $scar = Scar::query()->create([
                'ncr_id' => $ncr->id,
                'scar_no' => $this->numbering->nextScarNumber(),
                'status' => 'draft',
                'supplier_name' => $data['supplier_name'],
                'supplier_site' => $data['supplier_site'] ?? null,
                'supplier_contact_name' => $data['supplier_contact_name'] ?? null,
                'supplier_contact_email' => $data['supplier_contact_email'] ?? null,
                'supplier_contact_phone' => $data['supplier_contact_phone'] ?? null,
                'po_do_invoice_no' => $data['po_do_invoice_no'] ?? null,
                'product_name' => $data['product_name'] ?? null,
                'product_code' => $data['product_code'] ?? null,
                'detected_area' => $data['detected_area'] ?? null,
                'issued_by' => $actor->attributes['name'] ?? null,
                'request_type' => $data['request_type'] ?? null,
                'severity' => $data['severity'] ?? null,
                'claim_quantity' => $data['claim_quantity'] ?? null,
                'uom' => $data['uom'] ?? null,
                'claim_value' => $data['claim_value'] ?? null,
                'problem_description' => $data['problem_description'] ?? null,
                'issue_owner_user_id' => $actor->id,
                'acknowledgement_due_at' => $data['acknowledgement_due_at'] ?? null,
                'containment_due_at' => $data['containment_due_at'] ?? null,
                'response_due_at' => $data['response_due_at'] ?? null,
                'verification_due_at' => $data['verification_due_at'] ?? null,
                'metadata' => $data['metadata'] ?? null,
            ]);

            StatusHistory::query()->create([
                'flow' => 'quality_scar',
                'flow_id' => $scar->id,
                'status' => 'draft',
                'actor_id' => $actor->id,
                'actor_role' => $actor->attributes['role'] ?? null,
                'actor_department' => $actor->attributes['department'] ?? null,
                'actor_company' => $actor->attributes['company'] ?? null,
                'comment' => $data['problem_description'] ?? null,
                'comment_tag' => 'creation',
                'transitioned_at' => Carbon::now(),
            ]);

            if (! $ncr->is_supplier_related) {
                $ncr->update(['is_supplier_related' => true]);
            }

            return $scar;
        });
    }

    /**
     * Issue a SCAR to the supplier.
     *
     * @param  Scar  $scar  The SCAR to issue
     * @param  Actor  $actor  The principal issuing the SCAR
     * @param  array{comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function issue(Scar $scar, Actor $actor, array $data = []): TransitionResult
    {
        return DB::transaction(function () use ($scar, $actor, $data): TransitionResult {
            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: 'issue',
                metadata: $data['metadata'] ?? null,
            );

            $result = $scar->transitionTo('issued', $context);

            if ($result->success) {
                $scar->update(['issuing_date' => Carbon::now()]);
            }

            return $result;
        });
    }

    /**
     * Acknowledge receipt of the SCAR by the supplier.
     *
     * @param  Scar  $scar  The SCAR to acknowledge
     * @param  Actor  $actor  The principal acknowledging
     * @param  array{comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function acknowledge(Scar $scar, Actor $actor, array $data = []): TransitionResult
    {
        $context = new TransitionContext(
            actor: $actor,
            comment: $data['comment'] ?? null,
            commentTag: 'acknowledgement',
            metadata: $data['metadata'] ?? null,
        );

        return $scar->transitionTo('acknowledged', $context);
    }

    /**
     * Submit containment action from the supplier.
     *
     * @param  Scar  $scar  The SCAR to update
     * @param  Actor  $actor  The principal submitting containment
     * @param  array{containment_response: string, comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function submitContainment(Scar $scar, Actor $actor, array $data): TransitionResult
    {
        return DB::transaction(function () use ($scar, $actor, $data): TransitionResult {
            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: 'containment',
                metadata: $data['metadata'] ?? null,
            );

            $result = $scar->transitionTo('containment_submitted', $context);

            if ($result->success) {
                $scar->update([
                    'containment_response' => $data['containment_response'],
                ]);
            }

            return $result;
        });
    }

    /**
     * Submit investigation response from the supplier.
     *
     * @param  Scar  $scar  The SCAR to update
     * @param  Actor  $actor  The principal submitting the response
     * @param  array{root_cause_response?: string|null, corrective_action_response?: string|null, comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function submitResponse(Scar $scar, Actor $actor, array $data = []): TransitionResult
    {
        return DB::transaction(function () use ($scar, $actor, $data): TransitionResult {
            if ($scar->status === 'acknowledged' || $scar->status === 'containment_submitted') {
                $interimContext = new TransitionContext(actor: $actor);
                $interimResult = $scar->transitionTo('under_investigation', $interimContext);

                if (! $interimResult->success) {
                    return $interimResult;
                }

                $scar->refresh();
            }

            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: 'response',
                metadata: $data['metadata'] ?? null,
            );

            $result = $scar->transitionTo('response_submitted', $context);

            if ($result->success) {
                $now = Carbon::now();
                $scar->update(array_filter([
                    'root_cause_response' => $data['root_cause_response'] ?? null,
                    'corrective_action_response' => $data['corrective_action_response'] ?? null,
                    'supplier_response_submitted_at' => $now,
                ], fn ($v) => $v !== null));
            }

            return $result;
        });
    }

    /**
     * Review a supplier response: accept or request revision.
     *
     * @param  Scar  $scar  The SCAR under review
     * @param  Actor  $actor  The quality reviewer
     * @param  array{accepted: bool, comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function review(Scar $scar, Actor $actor, array $data): TransitionResult
    {
        return DB::transaction(function () use ($scar, $actor, $data): TransitionResult {
            if ($scar->status === 'response_submitted') {
                $reviewContext = new TransitionContext(actor: $actor);
                $reviewStart = $scar->transitionTo('under_review', $reviewContext);

                if (! $reviewStart->success) {
                    return $reviewStart;
                }

                $scar->refresh();
            }

            $accepted = $data['accepted'];
            $toCode = $accepted ? 'verification_pending' : 'action_required';
            $commentTag = $accepted ? 'review_accepted' : 'review_revision';

            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: $commentTag,
                metadata: $data['metadata'] ?? null,
            );

            return $scar->transitionTo($toCode, $context);
        });
    }

    /**
     * Verify and close a SCAR after effectiveness confirmation.
     *
     * @param  Scar  $scar  The SCAR to verify and close
     * @param  Actor  $actor  The verifier
     * @param  array{commercial_resolution_type?: string|null, commercial_resolution_amount?: string|null, comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function verify(Scar $scar, Actor $actor, array $data = []): TransitionResult
    {
        return DB::transaction(function () use ($scar, $actor, $data): TransitionResult {
            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: 'verification',
                metadata: $data['metadata'] ?? null,
            );

            $result = $scar->transitionTo('closed', $context);

            if ($result->success) {
                $now = Carbon::now();
                $scar->update(array_filter([
                    'commercial_resolution_type' => $data['commercial_resolution_type'] ?? null,
                    'commercial_resolution_amount' => $data['commercial_resolution_amount'] ?? null,
                    'commercial_resolution_at' => isset($data['commercial_resolution_type']) ? $now : null,
                    'verified_by_user_id' => $actor->id,
                    'verified_at' => $now,
                    'closed_by_user_id' => $actor->id,
                    'closed_at' => $now,
                ], fn ($v) => $v !== null));
            }

            return $result;
        });
    }

    /**
     * Close a SCAR that has already been verified.
     *
     * @param  Scar  $scar  The SCAR to close
     * @param  Actor  $actor  The principal closing the SCAR
     * @param  array{comment?: string|null, metadata?: array<string, mixed>|null}  $data
     */
    public function close(Scar $scar, Actor $actor, array $data = []): TransitionResult
    {
        return DB::transaction(function () use ($scar, $actor, $data): TransitionResult {
            $context = new TransitionContext(
                actor: $actor,
                comment: $data['comment'] ?? null,
                commentTag: 'close',
                metadata: $data['metadata'] ?? null,
            );

            $result = $scar->transitionTo('closed', $context);

            if ($result->success) {
                $now = Carbon::now();
                $scar->update([
                    'closed_by_user_id' => $actor->id,
                    'closed_at' => $now,
                ]);
            }

            return $result;
        });
    }
}
