<?php

namespace App\Base\Database\Contracts;

/**
 * Marks an Eloquent model whose table grows with use and is never bounded by
 * the size of the business: logs, history, runs, audit trails, event streams,
 * ledgers. Rows accumulate for as long as the instance lives, so there is no
 * point at which "all rows" is a sane amount to load into PHP.
 *
 * The marker is explicit rather than inferred from a name so the contract is
 * reviewable. It drives GrowingTableUnboundedLoadRule in static analysis: a
 * ->get(), ->all(), ->pluck() or ::all() on such a model must be limited,
 * paged, reduced by the database first, or filtered to one partition the
 * model declares with #[PartitionedBy] (see docs/architecture/query-bounds.md).
 */
interface GrowingTable {}
