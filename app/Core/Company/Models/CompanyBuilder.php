<?php

namespace App\Core\Company\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Company queries, with the erasure guards attached to the query path as well.
 *
 * Eloquent's own `Builder::forceDelete()` erases every matched row in a single
 * statement without ever loading a model, which is a one-line way around every
 * rule `Company::forceDelete()` enforces. This builder replaces it with a loop
 * that goes through the model, so the guards apply however the erasure is
 * written.
 *
 * Companies are few and erasing them is rare, so paying for one model per row
 * costs nothing worth keeping a bypass for.
 *
 * @extends Builder<Company>
 */
class CompanyBuilder extends Builder
{
    /**
     * Erase every matched company, one guarded model at a time.
     *
     * Soft-deleted companies are included, which is what the method being
     * replaced did: it read the underlying query, where no scope applies.
     *
     * @return int the number of companies erased
     */
    public function forceDelete(): int
    {
        $erased = 0;

        foreach ($this->withoutGlobalScope(SoftDeletingScope::class)->get() as $company) {
            $erased += $company->forceDelete() === true ? 1 : 0;
        }

        return $erased;
    }
}
