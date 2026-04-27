<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Company\Livewire\Companies;

use App\Base\Foundation\Livewire\Concerns\ResetsPaginationOnSearch;
use App\Modules\Core\Company\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Session;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use ResetsPaginationOnSearch;
    use WithPagination;

    public string $search = '';

    public function statusVariant(string $status): string
    {
        return match ($status) {
            'active' => 'success',
            'suspended' => 'danger',
            'pending' => 'warning',
            default => 'default',
        };
    }

    public function delete(int $companyId): void
    {
        $company = Company::query()->withCount('children')->findOrFail($companyId);

        if ($company->id === Company::LICENSEE_ID) {
            Session::flash('error', __('The licensee company cannot be deleted.'));

            return;
        }

        if ($company->children_count > 0) {
            Session::flash('error', __('Cannot delete a company that has subsidiaries.'));

            return;
        }

        $company->delete();

        Session::flash('success', __('Company deleted successfully.'));
    }

    public function render(): View
    {
        return view('livewire.admin.companies.index', [
            'companies' => Company::query()
                ->with('parent')
                ->when($this->search, function ($query, $search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('legal_name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('jurisdiction', 'like', '%'.$search.'%');
                })
                ->latest()
                ->paginate(15),
        ]);
    }
}
