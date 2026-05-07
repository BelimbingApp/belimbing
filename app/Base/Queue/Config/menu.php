<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

return [
    'items' => [
        [
            'id' => 'system.failed-jobs',
            'label' => 'Failed Jobs',
            'icon' => 'heroicon-o-exclamation-triangle',
            'route' => 'admin.system.failed-jobs.index',
            'permission' => 'admin.system_failed_job.list',
            'parent' => 'system',
        ],
        [
            'id' => 'system.job-batches',
            'label' => 'Job Batches',
            'icon' => 'heroicon-o-squares-plus',
            'route' => 'admin.system.job-batches.index',
            'permission' => 'admin.system_job_batch.list',
            'parent' => 'system',
        ],
    ],
];
