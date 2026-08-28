<?php

namespace App\Base\Schedule\Contracts;

/**
 * The explicit owner contract for editing a contributor task's cadence (#398).
 *
 * A contributor whose tasks may be rescheduled from the Schedule page
 * implements this alongside ScheduleContributor and marks the affected
 * ScheduleTask DTOs `editable: true`. A contributor that does not implement it
 * keeps its tasks read-only on the page — an edit the owner cannot persist
 * and execute must not exist as a shadow override the owner ignores.
 *
 * Implementations receive the already-validated, normalized expression and
 * return false (rather than throw) when they cannot persist it; the page
 * reports that as the owner refusing the edit.
 */
interface ScheduleCadenceContributor
{
    public function updateCadence(string $key, string $expression): bool;

    public function resetCadence(string $key): bool;
}
