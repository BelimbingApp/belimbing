<?php

namespace App\Base\Database\Concerns;

/**
 * Marker for migration files whose schema is still under development.
 *
 * `php artisan migrate --dev` may drop and rerun tables created by migrations
 * using this trait. Keep this marker source-local so coding agents can find it
 * beside the schema they are editing.
 */
trait IncubatingSchema {}
