<?php

namespace App\Modules\Core\Company\Models\Concerns;

use App\Modules\Core\Company\Models\CompanyRelationship;

trait ResolvesRelationshipCompanies
{
    /**
     * @return list<int>
     */
    private function relationshipCompanyIds(mixed $relationshipId): array
    {
        if ($relationshipId === null || $relationshipId === '') {
            return [];
        }

        $relationship = CompanyRelationship::query()->find((int) $relationshipId);

        if (! $relationship instanceof CompanyRelationship) {
            return [];
        }

        return array_values(array_filter([
            $relationship->company_id !== null ? (int) $relationship->company_id : null,
            $relationship->related_company_id !== null ? (int) $relationship->related_company_id : null,
        ]));
    }
}
