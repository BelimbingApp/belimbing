<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'domains' => [
        'operations' => 'Operational modules, including quality management.',
    ],

    'capabilities' => [
        // NCR module capabilities
        'operations.quality.ncr.create',
        'operations.quality.ncr.view',
        'operations.quality.ncr.triage',
        'operations.quality.ncr.assign',
        'operations.quality.ncr.respond',
        'operations.quality.ncr.review',
        'operations.quality.ncr.rework',
        'operations.quality.ncr.verify',
        'operations.quality.ncr.close',
        'operations.quality.ncr.reject',

        // SCAR module capabilities
        'operations.quality.scar.create',
        'operations.quality.scar.view',
        'operations.quality.scar.issue',
        'operations.quality.scar.review',
        'operations.quality.scar.accept',
        'operations.quality.scar.rework',
        'operations.quality.scar.close',
        'operations.quality.scar.cancel',
        'operations.quality.scar.reject',

        // Evidence capabilities
        'operations.quality.evidence.upload',
        'operations.quality.evidence.view',

        // Knowledge and reporting capabilities
        'operations.quality.knowledge.view',
        'operations.quality.report.view',

        // Workflow transitions use the same menu-aligned capability keys.
    ],
];
