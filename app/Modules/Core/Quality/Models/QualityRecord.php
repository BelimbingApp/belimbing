<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Models;

use App\Modules\Core\Quality\Models\Concerns\HasQualityEvents;
use App\Modules\Core\Quality\Models\Concerns\HasQualityEvidence;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class QualityRecord extends Model
{
    use HasQualityEvents;
    use HasQualityEvidence;

    protected function qualityUserRelation(string $foreignKey): BelongsTo
    {
        return $this->belongsTo(User::class, $foreignKey);
    }
}
