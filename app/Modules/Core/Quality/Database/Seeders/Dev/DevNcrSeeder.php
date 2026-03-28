<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Database\Seeders\Dev;

use App\Base\Authz\DTO\Actor;
use App\Base\Database\Seeders\DevSeeder;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Quality\Database\Seeders\NcrWorkflowSeeder;
use App\Modules\Core\Quality\Database\Seeders\ScarWorkflowSeeder;
use App\Modules\Core\Quality\Models\Ncr;
use App\Modules\Core\Quality\Services\NcrService;
use App\Modules\Core\Quality\Services\ScarService;
use App\Modules\Core\User\Database\Seeders\Dev\DevUserSeeder;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Carbon;

/**
 * Seeds sample NCRs, CAPAs, and SCARs at various lifecycle stages.
 *
 * Creates realistic quality cases for development:
 * - open NCRs awaiting triage
 * - triaged and assigned NCRs under investigation
 * - NCRs with department responses under review
 * - verified and closed NCRs
 * - rejected NCRs
 * - NCRs with linked SCARs at various stages
 */
class DevNcrSeeder extends DevSeeder
{
    protected array $dependencies = [
        DevUserSeeder::class,
    ];

    protected function seed(): void
    {
        (new NcrWorkflowSeeder)->run();
        (new ScarWorkflowSeeder)->run();

        $company = Company::query()->where('id', Company::LICENSEE_ID)->first();

        if (! $company) {
            return;
        }

        $users = User::query()
            ->where('company_id', $company->id)
            ->limit(3)
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        $reporter = $users->first();
        $qualityMgr = $users->count() > 1 ? $users->get(1) : $reporter;
        $prodMgr = $users->count() > 2 ? $users->get(2) : $qualityMgr;

        $ncrService = app(NcrService::class);
        $scarService = app(ScarService::class);

        $this->seedOpenNcrs($ncrService, $company, $reporter);
        $this->seedTriagedNcrs($ncrService, $company, $reporter, $qualityMgr);
        $this->seedAssignedNcrs($ncrService, $company, $reporter, $qualityMgr);
        $this->seedUnderReviewNcrs($ncrService, $company, $reporter, $qualityMgr, $prodMgr);
        $this->seedClosedNcrs($ncrService, $company, $reporter, $qualityMgr, $prodMgr);
        $this->seedRejectedNcr($ncrService, $company, $reporter, $qualityMgr);
        $this->seedNcrWithScar($ncrService, $scarService, $company, $reporter, $qualityMgr);
    }

    /**
     * Seed a single NCR scenario when the title is not already present.
     *
     * @param  array<string, mixed>  $openData
     * @param  array<int, array{action: string, actor?: User, payload?: array<string, mixed>}>  $steps
     */
    private function seedNcrScenario(
        NcrService $ncrService,
        Company $company,
        User $reporter,
        string $title,
        array $openData,
        array $steps = [],
    ): ?Ncr {
        if ($this->ncrExists($company, $title)) {
            return null;
        }

        $ncr = $this->openNcr($ncrService, $company, $reporter, ['title' => $title] + $openData);

        foreach ($steps as $step) {
            $ncr->refresh();
            $this->applyNcrStep($ncrService, $ncr, $step);
        }

        return $ncr;
    }

    /**
     * Open an NCR with the shared dev-seeding defaults.
     *
     * @param  array<string, mixed>  $data
     */
    private function openNcr(NcrService $ncrService, Company $company, User $reporter, array $data): Ncr
    {
        return $ncrService->open(
            Actor::forUser($reporter),
            [
                'company_id' => $company->id,
                'reported_by_name' => $reporter->name,
                'source' => 'manual',
                ...$data,
            ],
        );
    }

    /**
     * Apply one workflow step to an NCR scenario.
     *
     * @param  array{action: string, actor?: User, payload?: array<string, mixed>}  $step
     */
    private function applyNcrStep(NcrService $ncrService, Ncr $ncr, array $step): void
    {
        match ($step['action']) {
            'triage' => $ncrService->triage($ncr, Actor::forUser($step['actor']), $step['payload'] ?? []),
            'assign' => $ncrService->assign($ncr, Actor::forUser($step['actor']), $step['payload'] ?? []),
            'submit_response' => $ncrService->submitResponse($ncr, Actor::forUser($step['actor']), $step['payload'] ?? []),
            'review' => $ncrService->review($ncr, Actor::forUser($step['actor']), $step['payload'] ?? []),
            'reject' => $ncrService->reject($ncr, Actor::forUser($step['actor']), $step['payload'] ?? []),
            'close' => $ncrService->close($ncr, Actor::forUser($step['actor']), $step['payload'] ?? []),
            'capa_update' => $ncr->capa?->update($step['payload'] ?? []),
            default => throw new \InvalidArgumentException('Unknown NCR seeding step ['.$step['action'].'].'),
        };
    }

    /**
     * Build a normalized workflow step definition.
     *
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor?: User, payload: array<string, mixed>}
     */
    private function workflowStep(string $action, array $payload = [], ?User $actor = null): array
    {
        $step = [
            'action' => $action,
            'payload' => $payload,
        ];

        if ($actor instanceof User) {
            $step['actor'] = $actor;
        }

        return $step;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function triageStep(User $actor, array $payload): array
    {
        return $this->workflowStep('triage', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function assignStep(User $actor, array $payload): array
    {
        return $this->workflowStep('assign', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function submitResponseStep(User $actor, array $payload): array
    {
        return $this->workflowStep('submit_response', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function reviewStep(User $actor, array $payload): array
    {
        return $this->workflowStep('review', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function rejectStep(User $actor, array $payload): array
    {
        return $this->workflowStep('reject', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, actor: User, payload: array<string, mixed>}
     */
    private function closeStep(User $actor, array $payload): array
    {
        return $this->workflowStep('close', $payload, $actor);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, payload: array<string, mixed>}
     */
    private function capaUpdateStep(array $payload): array
    {
        return $this->workflowStep('capa_update', $payload);
    }

    /**
     * Determine whether an NCR title is already seeded for the company.
     */
    private function ncrExists(Company $company, string $title): bool
    {
        return Ncr::query()
            ->where('title', $title)
            ->where('company_id', $company->id)
            ->exists();
    }

    /**
     * Create NCRs that remain in 'open' status awaiting triage.
     */
    private function seedOpenNcrs(NcrService $ncrService, Company $company, User $reporter): void
    {
        $cases = [
            [
                'ncr_kind' => 'internal',
                'title' => 'Dimension out of tolerance on CNC bracket batch',
                'severity' => 'major',
                'summary' => 'CNC machined brackets from production run PR-2026-0147 measured 102.3mm instead of 100.0mm ±0.5mm specification. Affects 240 pcs from night shift output.',
                'product_name' => 'CNC Bracket Assembly',
                'product_code' => 'BK-4420',
                'quantity_affected' => '240.0000',
                'uom' => 'pcs',
            ],
            [
                'ncr_kind' => 'customer',
                'title' => 'Customer complaint — surface finish defect on panel',
                'severity' => 'critical',
                'summary' => 'Customer Nusantara Trading reported visible scratch marks and uneven coating on 15 panels from SO-20260312. Photos attached in email dated 2026-03-20.',
                'product_name' => 'Coated Panel Type A',
                'product_code' => 'PA-1100',
                'quantity_affected' => '15.0000',
                'uom' => 'pcs',
            ],
            [
                'ncr_kind' => 'internal',
                'title' => 'Wrong material used in sub-assembly',
                'severity' => 'minor',
                'summary' => 'Store issued SS304 instead of SS316 for sub-assembly SA-2200. Caught during in-process QC check before final assembly.',
                'product_name' => 'Sub-Assembly SA-2200',
                'product_code' => 'SA-2200',
                'quantity_affected' => '50.0000',
                'uom' => 'sets',
            ],
        ];

        foreach ($cases as $data) {
            $this->seedNcrScenario($ncrService, $company, $reporter, $data['title'], $data);
        }
    }

    /**
     * Create NCRs advanced to 'under_triage' with triage findings.
     */
    private function seedTriagedNcrs(NcrService $ncrService, Company $company, User $reporter, User $qualityMgr): void
    {
        $this->seedNcrScenario(
            $ncrService,
            $company,
            $reporter,
            'Packaging damage during internal transfer',
            [
                'ncr_kind' => 'internal',
                'severity' => 'minor',
                'summary' => 'Forklift damage to 3 cartons during transfer from warehouse to shipping area. Outer packaging torn, inner product needs inspection.',
                'product_name' => 'Finished Goods Carton',
                'product_code' => 'FG-8800',
                'quantity_affected' => '3.0000',
                'uom' => 'cartons',
            ],
            [
                $this->triageStep($qualityMgr, [
                    'triage_summary' => 'Minor packaging damage. Products inside appear intact but require visual inspection. Root cause likely forklift operator error.',
                    'severity' => 'minor',
                    'classification' => 'handling_damage',
                ]),
            ],
        );
    }

    /**
     * Create NCRs advanced to 'assigned' to a department.
     */
    private function seedAssignedNcrs(NcrService $ncrService, Company $company, User $reporter, User $qualityMgr): void
    {
        $this->seedNcrScenario(
            $ncrService,
            $company,
            $reporter,
            'Welding porosity on frame assembly',
            [
                'ncr_kind' => 'internal',
                'severity' => 'major',
                'summary' => 'Visual inspection revealed porosity in TIG welds on 12 frame assemblies. Welding parameters may have drifted. Lot FA-2026-0089.',
                'product_name' => 'Frame Assembly FA-100',
                'product_code' => 'FA-100',
                'quantity_affected' => '12.0000',
                'uom' => 'pcs',
                'source' => 'inspection',
            ],
            [
                $this->triageStep($qualityMgr, [
                    'triage_summary' => 'Welding defect confirmed. Assign to production for root cause analysis and containment. Check gas flow settings and wire batch.',
                    'severity' => 'major',
                    'classification' => 'welding_defect',
                ]),
                $this->assignStep($qualityMgr, [
                    'current_owner_department' => 'Production',
                    'assignment_comment' => 'Please investigate welding parameters, gas flow rate, and operator certification for the night shift on 2026-03-18.',
                    'assignment_due_at' => Carbon::now()->addDays(5),
                ]),
                $this->capaUpdateStep([
                    'investigation_result' => 'Assigned to production for welding parameter investigation. Suspected gas flow drift on Station WS-03.',
                ]),
            ],
        );
    }

    /**
     * Create NCRs with department responses, now under quality review.
     */
    private function seedUnderReviewNcrs(NcrService $ncrService, Company $company, User $reporter, User $qualityMgr, User $prodMgr): void
    {
        $this->seedNcrScenario(
            $ncrService,
            $company,
            $reporter,
            'Label misprint on export shipment cartons',
            [
                'ncr_kind' => 'internal',
                'severity' => 'major',
                'summary' => 'Shipping labels on 80 cartons show wrong destination port code. Discovered during pre-shipment audit. Shipment EX-2026-0023.',
                'product_name' => 'Export Carton Label',
                'product_code' => 'LB-EX-001',
                'quantity_affected' => '80.0000',
                'uom' => 'pcs',
            ],
            [
                $this->triageStep($qualityMgr, [
                    'triage_summary' => 'Label error confirmed. Assign to shipping/logistics for immediate containment and re-labeling.',
                    'severity' => 'major',
                    'classification' => 'labeling_error',
                ]),
                $this->assignStep($qualityMgr, [
                    'current_owner_department' => 'Logistics',
                    'assignment_comment' => 'Contain all cartons from EX-2026-0023. Re-label with correct port code before shipment.',
                ]),
                $this->submitResponseStep($prodMgr, [
                    'containment_action' => 'All 80 cartons quarantined in staging area. Re-labeling completed within 4 hours of discovery.',
                    'root_cause_occurred' => 'Label template was updated on 2026-03-10 but the old template file was not archived. Operator selected the wrong version from the shared drive.',
                    'root_cause_leakage' => 'No version control on label templates. Pre-shipment audit is manual and relies on checker experience.',
                    'corrective_action_occurred' => 'Implement label template version control with date-stamped filenames. Old templates moved to archive folder with read-only access.',
                    'corrective_action_leakage' => 'Add port code validation step to pre-shipment checklist. Barcode scan verification for destination codes.',
                ]),
                $this->capaUpdateStep([
                    'investigation_result' => 'Label template version error. Operator used outdated template. No version control system in place.',
                    'response_by_user_id' => $prodMgr->id,
                    'responded_at' => Carbon::now(),
                ]),
            ],
        );
    }

    /**
     * Create NCRs that completed the full lifecycle through to closure.
     */
    private function seedClosedNcrs(NcrService $ncrService, Company $company, User $reporter, User $qualityMgr, User $prodMgr): void
    {
        $this->seedNcrScenario(
            $ncrService,
            $company,
            $reporter,
            'Incoming raw material hardness out of spec',
            [
                'ncr_kind' => 'internal',
                'severity' => 'major',
                'summary' => 'Incoming inspection on steel bar batch SB-2026-0055 showed Rockwell hardness at HRC 48 vs spec HRC 40-45. 2 tonnes affected.',
                'product_name' => 'Steel Bar Grade 4140',
                'product_code' => 'RM-4140',
                'quantity_affected' => '2000.0000',
                'uom' => 'kg',
                'source' => 'inspection',
                'is_supplier_related' => true,
            ],
            [
                $this->triageStep($qualityMgr, [
                    'triage_summary' => 'Material hardness confirmed out of spec. Quarantine batch. Coordinate with procurement for supplier notification.',
                    'severity' => 'major',
                    'classification' => 'incoming_material_defect',
                ]),
                $this->assignStep($qualityMgr, [
                    'current_owner_department' => 'QAC/QC',
                    'assignment_comment' => 'Investigate supplier heat treatment records. Coordinate return or re-heat-treatment if feasible.',
                ]),
                $this->submitResponseStep($prodMgr, [
                    'containment_action' => 'Batch SB-2026-0055 quarantined in reject bay. All downstream WIP using this batch recalled — 45 pcs identified and segregated.',
                    'root_cause_occurred' => 'Supplier heat treatment cycle was shortened due to furnace scheduling conflict. Mill test certificate shows correct chemistry but hardness test was skipped at supplier end.',
                    'corrective_action_occurred' => 'Supplier agreed to re-heat-treat the batch at their cost. Updated supplier QA agreement to mandate hardness testing on every heat.',
                    'effective_date_occurred' => Carbon::now()->subDays(3),
                ]),
                $this->capaUpdateStep([
                    'investigation_result' => 'Supplier heat treatment process deviation confirmed. Mill cert chemistry OK but hardness not tested at source.',
                    'response_by_user_id' => $prodMgr->id,
                    'responded_at' => Carbon::now()->subDays(5),
                ]),
                $this->reviewStep($qualityMgr, [
                    'approved' => true,
                    'quality_review_comment' => 'Root cause and corrective action accepted. Supplier re-heat-treatment completed and verification test passed. Close case.',
                ]),
                $this->capaUpdateStep([
                    'verification_result' => 'effective',
                    'verified_by_user_id' => $qualityMgr->id,
                    'verified_at' => Carbon::now()->subDays(1),
                ]),
                $this->closeStep($qualityMgr, [
                    'comment' => 'Corrective action verified effective. Supplier agreed to updated QA protocol. Case closed.',
                ]),
            ],
        );
    }

    /**
     * Create a rejected NCR.
     */
    private function seedRejectedNcr(NcrService $ncrService, Company $company, User $reporter, User $qualityMgr): void
    {
        $this->seedNcrScenario(
            $ncrService,
            $company,
            $reporter,
            'Cosmetic mark on sample — not a defect',
            [
                'ncr_kind' => 'internal',
                'severity' => 'observation',
                'summary' => 'Operator reported a faint tool mark on sample piece from CNC run. Mark is within cosmetic acceptance criteria per drawing note 4.',
                'product_name' => 'CNC Sample Part',
                'product_code' => 'SP-9900',
                'quantity_affected' => '1.0000',
                'uom' => 'pcs',
            ],
            [
                $this->rejectStep($qualityMgr, [
                    'reject_reason' => 'Mark is within acceptable cosmetic tolerance per drawing note 4. Not a nonconformance. Operator advised on acceptance criteria.',
                ]),
            ],
        );
    }

    /**
     * Create an NCR with a linked SCAR for supplier corrective action.
     */
    private function seedNcrWithScar(
        NcrService $ncrService,
        ScarService $scarService,
        Company $company,
        User $reporter,
        User $qualityMgr,
    ): void {
        $ncr = $this->seedNcrScenario(
            $ncrService,
            $company,
            $reporter,
            'Supplier delivered wrong grade fasteners',
            [
                'ncr_kind' => 'internal',
                'severity' => 'critical',
                'summary' => 'Incoming inspection on PO-2026-1200 found Grade 8.8 bolts delivered instead of Grade 10.9 as ordered. 500 pcs affected. Used in safety-critical assembly.',
                'product_name' => 'Hex Bolt M12x50',
                'product_code' => 'HB-1250',
                'quantity_affected' => '500.0000',
                'uom' => 'pcs',
                'source' => 'inspection',
                'is_supplier_related' => true,
            ],
            [
                $this->triageStep($qualityMgr, [
                    'triage_summary' => 'Critical: wrong grade fasteners in safety-critical application. Immediate containment required. SCAR to be issued to supplier.',
                    'severity' => 'critical',
                    'classification' => 'wrong_material_supplied',
                ]),
                $this->assignStep($qualityMgr, [
                    'current_owner_department' => 'Procurement',
                    'assignment_comment' => 'Quarantine all fasteners from PO-2026-1200. Issue SCAR to Borneo Logistics for wrong grade delivery. Check if any Grade 8.8 bolts were already used in production.',
                    'assignment_due_at' => Carbon::now()->addDays(3),
                ]),
                $this->capaUpdateStep([
                    'investigation_result' => 'Wrong grade fasteners confirmed via hardness and marking check. SCAR required for supplier accountability.',
                ]),
            ],
        );

        if (! $ncr) {
            return;
        }

        // Create linked SCAR
        $scar = $scarService->create(
            Actor::forUser($qualityMgr),
            $ncr,
            [
                'supplier_name' => 'Borneo Logistics',
                'supplier_contact_name' => 'Ahmad Razak',
                'supplier_contact_email' => 'razak.ahmad@borneologistics.my',
                'supplier_contact_phone' => '+60 82-456789',
                'po_do_invoice_no' => 'PO-2026-1200',
                'product_name' => 'Hex Bolt M12x50 Grade 10.9',
                'product_code' => 'HB-1250',
                'detected_area' => 'Incoming QC',
                'dimension' => 'M12x50',
                'request_type' => 'corrective_action_and_compensation',
                'severity' => 'critical',
                'claim_quantity' => '500.0000',
                'uom' => 'pcs',
                'claim_value' => '2500.00',
                'problem_description' => 'PO-2026-1200 specified Grade 10.9 Hex Bolt M12x50. Delivery contained Grade 8.8 per marking and hardness test. 500 pcs affected. These fasteners are used in safety-critical frame assemblies. Immediate replacement with correct grade required.',
                'response_due_at' => Carbon::now()->addDays(7),
            ],
        );

        // Issue SCAR to supplier
        $scarService->issue($scar, Actor::forUser($qualityMgr));

        // Supplier acknowledges
        $scar->refresh();
        $scarService->acknowledge($scar, Actor::forUser($qualityMgr), [
            'comment' => 'Supplier acknowledged receipt. Committed to respond within 5 business days.',
        ]);

        // Supplier submits containment
        $scar->refresh();
        $scarService->submitContainment($scar, Actor::forUser($qualityMgr), [
            'containment_response' => 'Replacement batch of 500 pcs Grade 10.9 bolts shipped via express courier. ETA 2 business days. Investigation into warehouse picking error initiated.',
        ]);
    }
}
