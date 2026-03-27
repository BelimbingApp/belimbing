<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Models\Concerns;

trait HasQualityRecordRelations
{
    use HasQualityEvents;
    use HasQualityEvidence;
}
