<?php

namespace App\Base\Database\Concerns;

/**
 * Marker for an idempotent, data-only migration that must replay after an
 * incubating table it reads or updates is rebuilt.
 *
 * This does not authorize schema creation, alteration, or deletion. The
 * migration's up() must be safe to run repeatedly and its down() must not
 * reintroduce historical data identities.
 */
trait ReplaysAfterIncubatingSchema {}
