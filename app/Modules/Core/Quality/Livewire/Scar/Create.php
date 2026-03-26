<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Quality\Livewire\Scar;

use App\Base\Authz\DTO\Actor;
use App\Modules\Core\Quality\Models\Ncr;
use App\Modules\Core\Quality\Services\ScarService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Create extends Component
{
    public ?int $ncr_id = null;

    public string $supplier_name = '';

    public ?string $supplier_site = null;

    public ?string $supplier_contact_name = null;

    public ?string $supplier_contact_email = null;

    public ?string $supplier_contact_phone = null;

    public ?string $po_do_invoice_no = null;

    public ?string $product_name = null;

    public ?string $product_code = null;

    public ?string $detected_area = null;

    public ?string $request_type = null;

    public ?string $severity = null;

    public ?string $claim_quantity = null;

    public ?string $uom = null;

    public ?string $claim_value = null;

    public ?string $problem_description = null;

    public function mount(): void
    {
        $this->ncr_id = (int) request()->query('ncr') ?: null;
    }

    public function store(ScarService $scarService): void
    {
        $validated = $this->validate([
            'ncr_id' => ['required', 'integer', 'exists:quality_ncrs,id'],
            'supplier_name' => ['required', 'string', 'max:255'],
            'supplier_site' => ['nullable', 'string', 'max:255'],
            'supplier_contact_name' => ['nullable', 'string', 'max:255'],
            'supplier_contact_email' => ['nullable', 'email', 'max:255'],
            'supplier_contact_phone' => ['nullable', 'string', 'max:50'],
            'po_do_invoice_no' => ['nullable', 'string', 'max:255'],
            'product_name' => ['nullable', 'string', 'max:255'],
            'product_code' => ['nullable', 'string', 'max:255'],
            'detected_area' => ['nullable', 'string', 'max:255'],
            'request_type' => ['nullable', Rule::in(array_keys(config('quality.scar_request_types')))],
            'severity' => ['nullable', Rule::in(array_keys(config('quality.severity_levels')))],
            'claim_quantity' => ['nullable', 'numeric', 'min:0'],
            'uom' => ['nullable', 'string', 'max:50'],
            'claim_value' => ['nullable', 'numeric', 'min:0'],
            'problem_description' => ['nullable', 'string', 'max:5000'],
        ]);

        $user = Auth::user();
        $actor = Actor::forUser($user);
        $ncr = Ncr::query()->findOrFail($validated['ncr_id']);

        $scar = $scarService->create($actor, $ncr, $validated);

        Session::flash('success', __('SCAR created successfully.'));

        $this->redirect(route('quality.scar.show', $scar), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.quality.scar.create', [
            'ncr' => $this->ncr_id ? Ncr::query()->find($this->ncr_id) : null,
        ]);
    }
}
