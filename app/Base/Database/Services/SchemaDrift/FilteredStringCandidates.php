<?php

namespace App\Base\Database\Services\SchemaDrift;

/**
 * A finite source list filtered by live-schema presence. It is safe to replay
 * all candidates only for a drop: present candidates are dropped and absent
 * candidates already satisfy the same postcondition.
 */
final readonly class FilteredStringCandidates
{
    /** @param  list<string>  $values */
    public function __construct(public array $values) {}
}
