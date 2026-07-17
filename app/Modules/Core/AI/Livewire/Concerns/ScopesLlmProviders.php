<?php

namespace App\Modules\Core\AI\Livewire\Concerns;

use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiProviderModel;
use Illuminate\Database\Eloquent\Builder;

trait ScopesLlmProviders
{
    /** @return Builder<AiProvider> */
    protected function companyLlmProviders(): Builder
    {
        $companyId = $this->getCompanyId();

        return AiProvider::query()
            ->llm()
            ->when(
                $companyId !== null,
                fn (Builder $query) => $query->forCompany($companyId),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            );
    }

    /** @return Builder<AiProviderModel> */
    protected function companyLlmModels(): Builder
    {
        $companyId = $this->getCompanyId();

        return AiProviderModel::query()->whereHas(
            'provider',
            fn (Builder $query) => $query
                ->llm()
                ->when(
                    $companyId !== null,
                    fn (Builder $query) => $query->forCompany($companyId),
                    fn (Builder $query) => $query->whereRaw('1 = 0'),
                ),
        );
    }
}
