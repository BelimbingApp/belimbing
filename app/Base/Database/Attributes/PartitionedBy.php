<?php

namespace App\Base\Database\Attributes;

use Attribute;

/**
 * Declares the columns that slice a growing table into one parent's rows:
 * a run's events, a session's artifacts, a process run's work items.
 *
 * GrowingTableUnboundedLoadRule accepts a load whose call chain filters on
 * one of these columns with an equality where(), because the result is
 * bounded by that parent's activity rather than by table growth. Declare
 * only columns whose partitions stay small enough to hold in memory; a
 * tenant or company id is not a partition, it is the whole table.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PartitionedBy
{
    /** @var list<string> */
    public readonly array $columns;

    public function __construct(string ...$columns)
    {
        $this->columns = array_values($columns);
    }
}
