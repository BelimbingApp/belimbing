<?php
namespace App\Modules\Operation\Quality\Models\Concerns;

use App\Modules\Operation\Quality\Models\QualityEvidence;
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
