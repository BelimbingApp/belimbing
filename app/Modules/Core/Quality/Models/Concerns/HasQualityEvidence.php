<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Models\Concerns;

use App\Modules\Core\Quality\Models\QualityEvidence;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasQualityEvidence
{
    /**
     * Get the evidence attachments for this quality record.
     */
    public function evidence(): MorphMany
    {
        return $this->morphMany(QualityEvidence::class, 'evidenceable')->with('mediaAsset');
    }
}
