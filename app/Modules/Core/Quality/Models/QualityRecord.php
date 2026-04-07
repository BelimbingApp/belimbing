<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Models;

use App\Modules\Core\Quality\Models\Concerns\HasQualityEvents;
use App\Modules\Core\Quality\Models\Concerns\HasQualityEvidence;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class QualityRecord extends Model
{
    use HasQualityEvents;
    use HasQualityEvidence;

    /**
     * Create a new Eloquent collection instance for the model.
     *
     * Overrides the parent resolution path so Laravel 13 does not try to
     * instantiate this abstract base model while discovering collection metadata.
     *
     * @param  array<int, static>  $models
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    protected function qualityUserRelation(string $foreignKey): BelongsTo
    {
        return $this->belongsTo(User::class, $foreignKey);
    }
}
