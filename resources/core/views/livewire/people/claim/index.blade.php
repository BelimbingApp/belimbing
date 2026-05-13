<?php
/** @var \App\Modules\People\Claim\Livewire\Index $this */
?>

<div>
    <x-slot name="title">{{ __('Claims') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Claims')" :subtitle="__('Configure claim categories, types, policies, assignments, and SBG migration context before enabling employee submissions.')">
            <x-slot name="help">
                {{ __('The Claim module uses a singular code namespace while the user-facing workbench remains Claims. Claim setup is country-neutral and hands approved reimbursement lines to Payroll.') }}
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        @if (session('error'))
            <x-ui.alert variant="danger">{{ session('error') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <x-ui.tabs
                    :tabs="$tabs"
                    :default="$tab"
                    size="sm"
                    persistence="none"
                    wire-action="setTab"
                    class="w-full lg:w-auto"
                >
                    @foreach ($tabs as $tabDef)
                        <x-ui.tab :id="$tabDef['id']" />
                    @endforeach
                </x-ui.tabs>

                @if (in_array($tab, ['categories', 'types', 'policies'], true))
                    <div class="w-full lg:w-80">
                        <x-ui.search-input
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search claim setup...') }}"
                        />
                    </div>
                @endif
            </div>

            @if (! $canManage && $tab !== 'requests')
                <x-ui.alert variant="warning">{{ __('You can view claim setup, but only claim managers can change it.') }}</x-ui.alert>
            @endif

            @if ($tab === 'requests')
                <div class="mb-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(22rem,0.8fr)]">
                    <x-ui.card :title="__('Submit Claim')">
                        @if ($currentEmployeeId === null)
                            <x-ui.alert variant="warning">{{ __('Your user account is not linked to an employee record, so claims cannot be submitted from this workbench yet.') }}</x-ui.alert>
                        @else
                            <form wire:submit="submitClaim" class="space-y-4">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.select id="claim-apply-assignment" wire:model.live="applyAssignmentId" label="{{ __('Assignment') }}" required :error="$errors->first('applyAssignmentId')">
                                        <option value="">{{ __('Select assignment') }}</option>
                                        @foreach ($myAssignments as $assignment)
                                            <option value="{{ $assignment->id }}">{{ $assignment->code }} &mdash; {{ $assignment->name }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    <x-ui.select id="claim-apply-line" wire:model="applyAssignmentLineId" label="{{ __('Claim Type') }}" required :error="$errors->first('applyAssignmentLineId')">
                                        <option value="">{{ __('Select claim type') }}</option>
                                        @foreach ($availableAssignmentLines as $line)
                                            <option value="{{ $line->id }}">{{ $line->type?->code }} &mdash; {{ $line->type?->name }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>

                                <div class="grid gap-4 md:grid-cols-3">
                                    <x-ui.select id="claim-apply-context" wire:model="applyContextId" label="{{ __('Context') }}" :error="$errors->first('applyContextId')">
                                        <option value="">{{ __('No context') }}</option>
                                        @foreach ($contexts as $context)
                                            <option value="{{ $context->id }}">{{ $context->code }} &mdash; {{ $context->label }}</option>
                                        @endforeach
                                    </x-ui.select>
                                    <x-ui.input id="claim-apply-incurred-on" type="date" wire:model="applyIncurredOn" label="{{ __('Incurred On') }}" required :error="$errors->first('applyIncurredOn')" />
                                    <x-ui.input id="claim-apply-amount" wire:model="applyRequestedAmount" label="{{ __('Amount') }}" required :error="$errors->first('applyRequestedAmount')" />
                                </div>

                                <div class="grid gap-4 md:grid-cols-3">
                                    <x-ui.input id="claim-apply-provider" wire:model="applyProviderName" label="{{ __('Provider') }}" :error="$errors->first('applyProviderName')" />
                                    <x-ui.input id="claim-apply-receipt" wire:model="applyReceiptNumber" label="{{ __('Receipt Number') }}" :error="$errors->first('applyReceiptNumber')" />
                                    <x-ui.input id="claim-apply-attachments" wire:model="applyAttachmentCount" label="{{ __('Attachment Count') }}" required :error="$errors->first('applyAttachmentCount')" />
                                </div>

                                <x-ui.input id="claim-apply-description" wire:model="applyDescription" label="{{ __('Description') }}" :error="$errors->first('applyDescription')" />

                                <div class="flex justify-end">
                                    <x-ui.button type="submit" variant="primary">{{ __('Submit Claim') }}</x-ui.button>
                                </div>
                            </form>
                        @endif
                    </x-ui.card>

                    <x-ui.card :title="__('Approval Action')">
                        <p class="mb-4 text-sm text-muted">{{ __('Claim approvers can approve or reject submitted requests. Workflow routing is captured as profile metadata until the shared Workflow module is wired in.') }}</p>
                        <x-ui.input id="claim-approval-reason" wire:model="approvalReason" label="{{ __('Decision Reason') }}" />
                    </x-ui.card>
                </div>

                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Reference') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Employee') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Requested') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-default bg-surface-card">
                            @forelse ($recentRequests as $request)
                                <tr wire:key="claim-request-{{ $request->id }}">
                                    <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $request->reference_number ?? __('Draft #:id', ['id' => $request->id]) }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-ink">{{ $request->employee?->full_name ?? __('Employee #:id', ['id' => $request->employee_id]) }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $request->currency }} {{ number_format((float) $request->requested_amount, 2) }}</td>
                                    <td class="px-table-cell-x py-table-cell-y"><x-ui.badge :variant="$this->statusVariant($request->status)">{{ __(str_replace('_', ' ', ucfirst($request->status))) }}</x-ui.badge></td>
                                    <td class="px-table-cell-x py-table-cell-y">
                                        <div class="flex flex-wrap justify-end gap-2">
                                            @if ($request->employee_id === $currentEmployeeId && in_array($request->status, [\App\Modules\People\Claim\Models\ClaimRequest::STATUS_DRAFT, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_SUBMITTED, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_NEEDS_MORE_INFO], true))
                                                <x-ui.button type="button" size="sm" variant="secondary" wire:click="withdrawOwnRequest({{ $request->id }})">{{ __('Withdraw') }}</x-ui.button>
                                            @endif
                                            @if ($canApprove && in_array($request->status, [\App\Modules\People\Claim\Models\ClaimRequest::STATUS_SUBMITTED, \App\Modules\People\Claim\Models\ClaimRequest::STATUS_RESUBMITTED], true))
                                                <x-ui.button type="button" size="sm" variant="primary" wire:click="approveRequest({{ $request->id }})">{{ __('Approve') }}</x-ui.button>
                                                <x-ui.button type="button" size="sm" variant="danger" wire:click="rejectRequest({{ $request->id }})">{{ __('Reject') }}</x-ui.button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No claim requests yet.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            @elseif ($tab === 'categories')
                @if ($canManage)
                    <x-ui.card :title="__('Create Claim Category')" class="mb-6">
                        <form wire:submit="createCategory" class="grid gap-4 md:grid-cols-[1fr_2fr_auto] md:items-end">
                            <x-ui.input id="claim-category-code" wire:model="categoryCode" label="{{ __('Code') }}" required :error="$errors->first('categoryCode')" />
                            <x-ui.input id="claim-category-name" wire:model="categoryName" label="{{ __('Name') }}" required :error="$errors->first('categoryName')" />
                            <x-ui.button type="submit" variant="primary">{{ __('Save Category') }}</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif

                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Code') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Name') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-default bg-surface-card">
                            @forelse ($categories as $category)
                                <tr wire:key="claim-category-{{ $category->id }}">
                                    <td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $category->code }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-ink">{{ $category->name }}</td>
                                    <td class="px-table-cell-x py-table-cell-y"><x-ui.badge variant="success">{{ __(ucfirst($category->status)) }}</x-ui.badge></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No claim categories yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            @elseif ($tab === 'types')
                @if ($canManage)
                    <x-ui.card :title="__('Create Claim Type')" class="mb-6">
                        <form wire:submit="createClaimType" class="space-y-4">
                            <div class="grid gap-4 md:grid-cols-3">
                                <x-ui.input id="claim-type-code" wire:model="typeCode" label="{{ __('Code') }}" required :error="$errors->first('typeCode')" />
                                <x-ui.input id="claim-type-name" wire:model="typeName" label="{{ __('Name') }}" required :error="$errors->first('typeName')" />
                                <x-ui.select id="claim-type-category" wire:model="typeCategoryId" label="{{ __('Category') }}" :error="$errors->first('typeCategoryId')">
                                    <option value="">{{ __('Uncategorised') }}</option>
                                    @foreach ($categories as $category)
                                        <option value="{{ $category->id }}">{{ $category->code }} — {{ $category->name }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>

                            <div class="grid gap-4 md:grid-cols-4">
                                <x-ui.select id="claim-type-unit" wire:model="typeDefaultUnit" label="{{ __('Unit') }}">
                                    <option value="amount">{{ __('Amount') }}</option>
                                    <option value="distance">{{ __('Distance') }}</option>
                                    <option value="quantity">{{ __('Quantity') }}</option>
                                    <option value="days">{{ __('Days') }}</option>
                                </x-ui.select>
                                <x-ui.select id="claim-type-receipt" wire:model="typeReceiptRequirement" label="{{ __('Receipt Rule') }}">
                                    <option value="always">{{ __('Always') }}</option>
                                    <option value="above_amount">{{ __('Above Amount') }}</option>
                                    <option value="never">{{ __('Never') }}</option>
                                </x-ui.select>
                                <x-ui.input id="claim-type-payroll-code" wire:model="typePayrollPayItemCode" label="{{ __('Payroll Code') }}" :error="$errors->first('typePayrollPayItemCode')" />
                                <x-ui.input id="claim-type-route" wire:model="typeApprovalRouteKey" label="{{ __('Alternative Route') }}" :error="$errors->first('typeApprovalRouteKey')" />
                            </div>

                            <div class="grid gap-4 md:grid-cols-4">
                                <x-ui.input id="claim-type-dr" wire:model="typeDebitAccountCode" label="{{ __('Account Code (DR)') }}" :error="$errors->first('typeDebitAccountCode')" />
                                <x-ui.input id="claim-type-cr" wire:model="typeCreditAccountCode" label="{{ __('Account Code (CR)') }}" :error="$errors->first('typeCreditAccountCode')" />
                                <div class="space-y-2 pt-6">
                                    <x-ui.checkbox id="claim-type-provider-required" wire:model="typeProviderRequired" label="{{ __('Provider required') }}" />
                                    <x-ui.checkbox id="claim-type-payroll-eligible" wire:model="typePayrollEligible" label="{{ __('Payroll eligible') }}" />
                                </div>
                                <div class="flex items-end"><x-ui.button type="submit" variant="primary">{{ __('Save Type') }}</x-ui.button></div>
                            </div>
                        </form>
                    </x-ui.card>
                @endif

                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80">
                            <tr>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Type') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Category') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Payroll / Accounts') }}</th>
                                <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Rules') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-default bg-surface-card">
                            @forelse ($types as $type)
                                <tr wire:key="claim-type-{{ $type->id }}">
                                    <td class="px-table-cell-x py-table-cell-y"><div class="font-medium text-ink">{{ $type->name }}</div><div class="font-mono text-xs text-muted">{{ $type->code }}</div></td>
                                    <td class="px-table-cell-x py-table-cell-y text-sm text-ink">{{ $type->category?->name ?? __('Uncategorised') }}</td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted"><div>{{ __('Payroll: :code', ['code' => $type->payroll_pay_item_code ?? '—']) }}</div><div>{{ __('DR: :dr / CR: :cr', ['dr' => $type->debit_account_code ?? '—', 'cr' => $type->credit_account_code ?? '—']) }}</div></td>
                                    <td class="px-table-cell-x py-table-cell-y text-xs text-muted">{{ __(str_replace('_', ' ', ucfirst($type->receipt_requirement))) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No claim types yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

            @elseif ($tab === 'policies')
                @if ($canManage)
                    <div class="mb-6 grid gap-6 xl:grid-cols-2">
                        <x-ui.card :title="__('Create Claim Policy')">
                            <form wire:submit="createPolicy" class="space-y-4">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.input id="claim-policy-code" wire:model="policyCode" label="{{ __('Code') }}" required :error="$errors->first('policyCode')" />
                                    <x-ui.input id="claim-policy-name" wire:model="policyName" label="{{ __('Name') }}" required :error="$errors->first('policyName')" />
                                </div>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.select id="claim-policy-mode" wire:model="policyItemMode" label="{{ __('Item Mode') }}">
                                        <option value="single_value">{{ __('Single Value') }}</option>
                                        <option value="range">{{ __('Range') }}</option>
                                        <option value="service_year">{{ __('Service Year') }}</option>
                                    </x-ui.select>
                                    <x-ui.input id="claim-policy-effective-from" type="date" wire:model="policyEffectiveFrom" label="{{ __('Effective From') }}" required />
                                </div>
                                <div class="grid gap-4 md:grid-cols-2">
                                    <x-ui.input id="claim-policy-rate-type" wire:model="policyRateType" label="{{ __('Rate Type') }}" />
                                    <x-ui.input id="claim-policy-approval-profile" wire:model="policyApprovalProfileKey" label="{{ __('Approval Profile') }}" />
                                </div>
                                <div class="flex flex-wrap items-center gap-4">
                                    <x-ui.checkbox id="claim-policy-auto" wire:model="policyAutoCalculated" label="{{ __('Auto calculated') }}" />
                                    <x-ui.checkbox id="claim-policy-encumber" wire:model="policyEncumberPending" label="{{ __('Encumber pending claims') }}" />
                                    <x-ui.button type="submit" variant="primary">{{ __('Save Policy') }}</x-ui.button>
                                </div>
                            </form>
                        </x-ui.card>

                        <x-ui.card :title="__('Add Policy Band')">
                            <form wire:submit="addPolicyBand" class="space-y-4">
                                <x-ui.select id="claim-band-policy" wire:model="bandPolicyId" label="{{ __('Policy') }}" required :error="$errors->first('bandPolicyId')">
                                    <option value="">{{ __('Select policy') }}</option>
                                    @foreach ($policies as $policy)
                                        <option value="{{ $policy->id }}">{{ $policy->code }} — {{ $policy->name }}</option>
                                    @endforeach
                                </x-ui.select>
                                <div class="grid gap-4 md:grid-cols-3">
                                    <x-ui.input id="claim-band-threshold" wire:model="bandThreshold" label="{{ __('Threshold') }}" />
                                    <x-ui.input id="claim-band-rate" wire:model="bandRate" label="{{ __('Rate') }}" required />
                                    <x-ui.input id="claim-band-per-claim" wire:model="bandPerClaimLimit" label="{{ __('Per Claim Limit') }}" />
                                </div>
                                <div class="grid gap-4 md:grid-cols-3">
                                    <x-ui.input id="claim-band-per-day" wire:model="bandPerDayUnitLimit" label="{{ __('Per Day / Unit Limit') }}" />
                                    <x-ui.input id="claim-band-per-month" wire:model="bandPerMonthLimit" label="{{ __('Per Month Limit') }}" />
                                    <x-ui.input id="claim-band-per-year" wire:model="bandPerYearLimit" label="{{ __('Per Year Limit') }}" />
                                </div>
                                <x-ui.button type="submit" variant="primary">{{ __('Add Band') }}</x-ui.button>
                            </form>
                        </x-ui.card>
                    </div>
                @endif

                <div class="grid gap-4">
                    @forelse ($policies as $policy)
                        <x-ui.card wire:key="claim-policy-{{ $policy->id }}">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <div><div class="font-medium text-ink">{{ $policy->name }}</div><div class="font-mono text-xs text-muted">{{ $policy->code }} · {{ __(str_replace('_', ' ', ucfirst($policy->item_mode))) }}</div></div>
                                <x-ui.badge variant="success">{{ __(ucfirst($policy->status)) }}</x-ui.badge>
                            </div>
                            <div class="mt-4 overflow-x-auto">
                                <table class="min-w-full divide-y divide-border-default text-xs">
                                    <thead class="bg-surface-subtle/80"><tr><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Threshold') }}</th><th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Rate') }}</th><th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Per Claim') }}</th><th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Per Month') }}</th><th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Per Year') }}</th></tr></thead>
                                    <tbody class="divide-y divide-border-default bg-surface-card">
                                        @forelse ($policy->bands as $band)
                                            <tr wire:key="claim-policy-band-{{ $band->id }}"><td class="px-table-cell-x py-table-cell-y text-muted">{{ $band->logical_operator }} {{ $band->threshold_value ?? __('Unlimited') }}</td><td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $band->rate }}</td><td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $band->per_claim_limit ?? '—' }}</td><td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $band->per_month_limit ?? '—' }}</td><td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $band->per_year_limit ?? '—' }}</td></tr>
                                        @empty
                                            <tr><td colspan="5" class="px-table-cell-x py-6 text-center text-muted">{{ __('No bands configured.') }}</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </x-ui.card>
                    @empty
                        <x-ui.alert variant="info">{{ __('No claim policies yet.') }}</x-ui.alert>
                    @endforelse
                </div>

            @elseif ($tab === 'assignments')
                @if ($canManage)
                    <div class="mb-6 grid gap-6 xl:grid-cols-2">
                        <x-ui.card :title="__('Create Claim Assignment')">
                            <form wire:submit="createAssignment" class="space-y-4">
                                <x-ui.input id="claim-assignment-code" wire:model="assignmentCode" label="{{ __('Code') }}" required />
                                <x-ui.input id="claim-assignment-name" wire:model="assignmentName" label="{{ __('Name') }}" required />
                                <x-ui.input id="claim-assignment-effective-from" type="date" wire:model="assignmentEffectiveFrom" label="{{ __('Effective From') }}" required />
                                <x-ui.button type="submit" variant="primary">{{ __('Save Assignment') }}</x-ui.button>
                            </form>
                        </x-ui.card>

                        <x-ui.card :title="__('Add Assignment Line')">
                            <form wire:submit="addAssignmentLine" class="space-y-4">
                                <x-ui.select id="claim-line-assignment" wire:model="lineAssignmentId" label="{{ __('Assignment') }}" required><option value="">{{ __('Select assignment') }}</option>@foreach ($assignments as $assignment)<option value="{{ $assignment->id }}">{{ $assignment->code }} — {{ $assignment->name }}</option>@endforeach</x-ui.select>
                                <x-ui.select id="claim-line-type" wire:model="lineClaimTypeId" label="{{ __('Claim Type') }}" required><option value="">{{ __('Select type') }}</option>@foreach ($types as $type)<option value="{{ $type->id }}">{{ $type->code }} — {{ $type->name }}</option>@endforeach</x-ui.select>
                                <x-ui.select id="claim-line-policy" wire:model="lineClaimPolicyId" label="{{ __('Claim Policy') }}" required><option value="">{{ __('Select policy') }}</option>@foreach ($policies as $policy)<option value="{{ $policy->id }}">{{ $policy->code }} — {{ $policy->name }}</option>@endforeach</x-ui.select>
                                <x-ui.input id="claim-line-combine-tag" wire:model="lineCombineTag" label="{{ __('Combine Tag') }}" />
                                <div class="flex flex-wrap gap-4"><x-ui.checkbox id="claim-line-combined" wire:model="lineUsesCombinedCap" label="{{ __('Uses combined cap') }}" /><x-ui.checkbox id="claim-line-hidden" wire:model="lineHiddenFromApplication" label="{{ __('Hidden from application') }}" /></div>
                                <x-ui.button type="submit" variant="primary">{{ __('Add Line') }}</x-ui.button>
                            </form>
                        </x-ui.card>
                    </div>
                @endif

                <div class="grid gap-4">
                    @forelse ($assignments as $assignment)
                        <x-ui.card wire:key="claim-assignment-{{ $assignment->id }}">
                            <div class="mb-3"><div class="font-medium text-ink">{{ $assignment->name }}</div><div class="font-mono text-xs text-muted">{{ $assignment->code }}</div></div>
                            <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                @forelse ($assignment->lines as $line)
                                    <div class="rounded-xl border border-border-default p-3" wire:key="claim-assignment-line-{{ $line->id }}"><div class="font-medium text-ink">{{ $line->type?->name }}</div><div class="text-xs text-muted">{{ $line->policy?->name }}</div>@if ($line->hidden_from_application)<x-ui.badge>{{ __('Hidden') }}</x-ui.badge>@endif</div>
                                @empty
                                    <p class="text-sm text-muted">{{ __('No claim types assigned yet.') }}</p>
                                @endforelse
                            </div>
                        </x-ui.card>
                    @empty
                        <x-ui.alert variant="info">{{ __('No claim assignments yet.') }}</x-ui.alert>
                    @endforelse
                </div>

            @elseif ($tab === 'contexts')
                @if ($canManage)
                    <x-ui.card :title="__('Create Claim Context')" class="mb-6">
                        <form wire:submit="createContext" class="grid gap-4 md:grid-cols-[1fr_2fr_1fr_auto] md:items-end">
                            <x-ui.input id="claim-context-code" wire:model="contextCode" label="{{ __('Code') }}" required />
                            <x-ui.input id="claim-context-label" wire:model="contextLabel" label="{{ __('Label') }}" required />
                            <x-ui.input id="claim-context-max" wire:model="contextMaxClaimLimit" label="{{ __('Max Limit') }}" />
                            <x-ui.button type="submit" variant="primary">{{ __('Save Context') }}</x-ui.button>
                        </form>
                    </x-ui.card>
                @endif

                <div class="overflow-x-auto -mx-card-inner px-card-inner">
                    <table class="min-w-full divide-y divide-border-default text-sm">
                        <thead class="bg-surface-subtle/80"><tr><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Code') }}</th><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Label') }}</th><th class="px-table-cell-x py-table-header-y text-right text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Max Limit') }}</th></tr></thead>
                        <tbody class="divide-y divide-border-default bg-surface-card">
                            @forelse ($contexts as $context)
                                <tr wire:key="claim-context-{{ $context->id }}"><td class="px-table-cell-x py-table-cell-y font-mono text-xs text-ink">{{ $context->code }}</td><td class="px-table-cell-x py-table-cell-y text-ink">{{ $context->label }}</td><td class="px-table-cell-x py-table-cell-y text-right tabular-nums text-ink">{{ $context->max_claim_limit ?? '—' }}</td></tr>
                            @empty
                                <tr><td colspan="3" class="px-table-cell-x py-10 text-center text-sm text-muted">{{ __('No claim contexts yet.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
