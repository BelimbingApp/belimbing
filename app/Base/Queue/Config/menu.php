<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'admin.system.failed-job',
            'label' => 'Failed Jobs',
            'icon' => 'heroicon-o-exclamation-triangle',
            'route' => 'admin.system.failed-jobs.index',
            'permission' => 'admin.system.failed-job.list',
            'parent' => 'admin.system.diagnostics',
        ],
        [
            'id' => 'admin.system.job-batch',
            'label' => 'Job Batches',
            'icon' => 'heroicon-o-squares-plus',
            'route' => 'admin.system.job-batches.index',
            'permission' => 'admin.system.job-batch.list',
            'parent' => 'admin.system.diagnostics',
        ],
    ],
];
