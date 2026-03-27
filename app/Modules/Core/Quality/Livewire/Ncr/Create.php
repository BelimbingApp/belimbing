<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Livewire\Ncr;

use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Quality\Services\NcrService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public string $ncr_kind = 'internal';

    public string $title = '';

    public ?string $severity = null;

    public ?string $classification = null;

    public ?string $summary = null;

    public ?string $product_name = null;

    public ?string $product_code = null;

    public ?string $quantity_affected = null;

    public ?string $uom = null;

    public bool $is_supplier_related = false;

    public ?string $reported_by_name = '';

    public ?string $reported_by_email = null;

    public ?string $source = null;

    public function store(NcrService $ncrService): void
    {
        $validated = $this->validate([
            'ncr_kind' => ['required', Rule::in(array_keys(config('quality.ncr_kinds')))],
            'title' => ['required', 'string', 'max:255'],
            'severity' => ['nullable', Rule::in(array_keys(config('quality.severity_levels')))],
            'classification' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:5000'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'product_code' => ['nullable', 'string', 'max:255'],
            'quantity_affected' => ['nullable', 'numeric', 'min:0'],
            'uom' => ['nullable', 'string', 'max:50'],
            'is_supplier_related' => ['boolean'],
            'reported_by_name' => ['required', 'string', 'max:255'],
            'reported_by_email' => ['nullable', 'email', 'max:255'],
            'source' => ['nullable', 'string', 'max:255'],
        ]);

        $user = Auth::user();
        $actor = Actor::forUser($user);

        $ncr = $ncrService->open($actor, [
            'company_id' => $actor->companyId,
            ...$validated,
        ]);

        Session::flash('success', __('NCR created successfully.'));

        $this->redirect(route('quality.ncr.show', $ncr), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.quality.ncr.create');
    }
}
