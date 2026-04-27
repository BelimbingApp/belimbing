<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\User\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A user-owned SQL query that renders as a pinnable page.
 *
 * Each user gets their own independent copy (copy-on-share model).
 * Slugs are unique per user for human-readable URLs.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $slug
 * @property string|null $prompt
 * @property string $sql_query
 * @property string|null $description
 * @property string|null $icon
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Query extends Model
{
    protected $table = 'user_database_queries';

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'prompt',
        'sql_query',
        'description',
        'icon',
    ];

    /**
     * Generate a unique slug for a given name, scoped to a user.
     *
     * Appends a numeric suffix (-2, -3, …) if the base slug already
     * exists for the given user.
     *
     * @param  string  $name  The query title to slugify
     * @param  int  $userId  The owning user's ID
     */
    public static function generateSlug(string $name, int $userId): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $suffix = 2;

        while (self::query()->where('user_id', $userId)->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Get the user who owns this query.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
