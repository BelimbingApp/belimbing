<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\BrowserArtifactType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Durable browser artifact — screenshots, snapshots, PDFs, evaluation results.
 *
 * The actual content is stored on disk at the storage_path. This model
 * carries the metadata needed to locate, describe, and link artifacts
 * to their originating browser session and tab.
 *
 * @property string $id
 * @property string $browser_session_id
 * @property BrowserArtifactType $type
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $related_url
 * @property string|null $related_tab_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read BrowserSession $browserSession
 */
class BrowserArtifact extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'ai_browser_artifacts';

    protected $fillable = [
        'id',
        'browser_session_id',
        'type',
        'storage_path',
        'mime_type',
        'size_bytes',
        'related_url',
        'related_tab_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BrowserArtifactType::class,
            'size_bytes' => 'integer',
        ];
    }

    public function browserSession(): BelongsTo
    {
        return $this->belongsTo(BrowserSession::class, 'browser_session_id');
    }
}
