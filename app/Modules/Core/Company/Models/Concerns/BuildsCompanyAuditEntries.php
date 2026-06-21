<?php

namespace App\Modules\Core\Company\Models\Concerns;

trait BuildsCompanyAuditEntries
{
    /**
     * @param  array<int, mixed>  $companyIds
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @return list<array<string, mixed>>
     */
    private function companyAuditEntries(array $companyIds, string $event, array $oldValues, array $newValues, ?int $excludedCompanyId = null): array
    {
        $entries = [];

        foreach ($companyIds as $companyId) {
            if ($companyId === null || $companyId === '') {
                continue;
            }

            $id = (int) $companyId;

            if ($id === $excludedCompanyId) {
                continue;
            }

            $entries['company#'.$id] = [
                'subject_name' => 'company',
                'subject_id' => $id,
                'event' => $event,
                'old_values' => $oldValues,
                'new_values' => $newValues,
            ];
        }

        return array_values($entries);
    }
}
