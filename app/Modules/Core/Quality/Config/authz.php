<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'capabilities' => [
        // NCR module capabilities
        'quality.ncr.create',
        'quality.ncr.view',
        'quality.ncr.triage',
        'quality.ncr.assign',
        'quality.ncr.respond',
        'quality.ncr.review',
        'quality.ncr.verify',
        'quality.ncr.close',
        'quality.ncr.reject',

        // SCAR module capabilities
        'quality.scar.create',
        'quality.scar.view',
        'quality.scar.issue',
        'quality.scar.review',
        'quality.scar.accept',
        'quality.scar.rework',
        'quality.scar.close',
        'quality.scar.cancel',
        'quality.scar.reject',

        // Evidence capabilities
        'quality.evidence.upload',
        'quality.evidence.view',

        // Knowledge and reporting capabilities
        'quality.knowledge.view',
        'quality.report.view',

        // Workflow transition capabilities (used by StatusTransition.capability)
        'workflow.quality_ncr.triage',
        'workflow.quality_ncr.assign',
        'workflow.quality_ncr.rework',
        'workflow.quality_ncr.verify',
        'workflow.quality_ncr.reject',
        'workflow.quality_ncr.close',
        'workflow.quality_scar.issue',
        'workflow.quality_scar.review',
        'workflow.quality_scar.accept',
        'workflow.quality_scar.rework',
        'workflow.quality_scar.close',
        'workflow.quality_scar.cancel',
        'workflow.quality_scar.reject',
    ],
];
