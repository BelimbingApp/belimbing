<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    /*
    |--------------------------------------------------------------------------
    | NCR Kinds
    |--------------------------------------------------------------------------
    |
    | Supported nonconformance report kinds. Each kind maps to a label.
    |
    */
    'ncr_kinds' => [
        'internal' => 'Internal Corrective Action',
        'customer' => 'Customer Complaint',
        'incoming_inspection' => 'Incoming Inspection',
        'process' => 'Process Nonconformance',
    ],

    /*
    |--------------------------------------------------------------------------
    | Severity Levels
    |--------------------------------------------------------------------------
    */
    'severity_levels' => [
        'critical' => 'Critical',
        'major' => 'Major',
        'minor' => 'Minor',
        'observation' => 'Observation',
    ],

    /*
    |--------------------------------------------------------------------------
    | Evidence Types
    |--------------------------------------------------------------------------
    |
    | Normalized evidence type codes and their labels.
    |
    */
    'evidence_types' => [
        'original_complaint' => 'Original Complaint',
        'department_support' => 'Department Support',
        'occurrence_evidence' => 'Occurrence Evidence',
        'leakage_evidence' => 'Leakage Evidence',
        'supplier_response' => 'Supplier Response',
        'commercial_evidence' => 'Commercial Evidence',
        'verification_evidence' => 'Verification Evidence',
        'inspection_report' => 'Inspection Report',
        'corrective_action_report' => 'Corrective Action Report',
    ],

    /*
    |--------------------------------------------------------------------------
    | SCAR Request Types
    |--------------------------------------------------------------------------
    */
    'scar_request_types' => [
        'corrective_action' => 'Corrective Action',
        'corrective_action_and_compensation' => 'Corrective Action & Compensation',
    ],

    /*
    |--------------------------------------------------------------------------
    | Numbering
    |--------------------------------------------------------------------------
    |
    | Default number prefixes and format. Licensees can override via
    | their own NumberingService implementation.
    |
    */
    'numbering' => [
        'ncr_prefix' => 'NCR',
        'scar_prefix' => 'SCAR',
        'pad_length' => 6,
    ],
];
