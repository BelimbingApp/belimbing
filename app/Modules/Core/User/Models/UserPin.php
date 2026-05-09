<?php
namespace App\Modules\Core\User\Models;

use App\Base\Menu\Services\PinMetadataNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user-pinned item for sidebar quick-access.
 *
 * Pins are identified by their normalized URL via a url_hash column,
 * regardless of origin (menu item, page, DB view, etc.).
 *
 * @property int $id
 * @property int $user_id
 * @property string $label
 * @property string $url
 * @property string $url_hash
 * @property string|null $icon
 * @property int $sort_order
 */
class UserPin extends Model
{
    protected $table = 'user_pins';

    protected $fillable = [
        'user_id',
        'label',
        'url',
        'url_hash',
        'icon',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    /**
     * Compute the url_hash from a raw URL using the canonical normalizer.
     */
    public static function hashUrl(string $url): string
    {
        return md5(
            app(PinMetadataNormalizer::class)->normalizeUrl($url)
        );
    }

    /**
     * Get the user who pinned this item.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
