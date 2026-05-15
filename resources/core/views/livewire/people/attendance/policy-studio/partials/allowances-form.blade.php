<div class="space-y-4">
    <x-ui.card>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="max-w-2xl">
                <h2 class="text-base font-semibold text-ink">{{ $editingAllowanceRuleId === null ? __('Create allowance rule') : __('Edit allowance rule') }}</h2>
                <p class="mt-1 text-sm text-muted">{{ __('Pick a recipe, fill only the fields that recipe needs, then validate the policy in Policy Studio before payroll handoff.') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($editingAllowanceRuleId !== null)
                    <x-ui.button type="button" variant="secondary" wire:click="cancelAllowanceEdit">{{ __('Cancel edit') }}</x-ui.button>
                @endif
                <x-ui.badge variant="info">{{ __('Deterministic setup') }}</x-ui.badge>
            </div>
        </div>

        <form wire:submit="saveAllowanceRule" class="mt-4 space-y-4">
            <div class="grid gap-3 md:grid-cols-5">
                @foreach ([
                    'always' => [__('Always'), __('Pay when policy applies.')],
                    'min_worked' => [__('Worked time'), __('Pay after minutes.')],
                    'clock_out_after' => [__('Late out'), __('Pay after a time.')],
                    'clock_out_window' => [__('Time window'), __('Pay inside window.')],
                    'min_worked_and_after' => [__('Worked + late'), __('Require both.')],
                ] as $presetKey => [$presetLabel, $presetHelp])
                    <button type="button" wire:click="$set('allowanceConditionPreset', '{{ $presetKey }}')" class="rounded-2xl border p-3 text-left transition hover:-translate-y-0.5 {{ $allowanceConditionPreset === $presetKey ? 'border-accent bg-surface-subtle' : 'border-border-default bg-surface-card' }}">
                        <div class="text-sm font-medium text-ink">{{ $presetLabel }}</div>
                        <div class="mt-1 text-xs text-muted">{{ $presetHelp }}</div>
                    </button>
                @endforeach
            </div>

            <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.45fr)]">
                <div class="space-y-4 rounded-2xl border border-border-default p-card-inner">
                    <div class="grid gap-4 md:grid-cols-2">
                        <x-ui.input id="attendance-allowance-code" wire:model="allowanceCode" label="{{ __('Code') }}" placeholder="{{ __('NIGHT_ALLOWANCE') }}" required :error="$errors->first('allowanceCode')" />
                        <x-ui.input id="attendance-allowance-name" wire:model="allowanceName" label="{{ __('Name') }}" placeholder="{{ __('Night allowance') }}" required :error="$errors->first('allowanceName')" />
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <x-ui.input id="attendance-allowance-amount" type="number" step="0.01" min="0.01" wire:model="allowanceAmount" label="{{ __('Amount') }}" required :error="$errors->first('allowanceAmount')" />
                        <x-ui.input id="attendance-allowance-pay-item" wire:model="allowancePayItemCode" label="{{ __('Payroll pay item') }}" placeholder="{{ __('night_allowance') }}" :error="$errors->first('allowancePayItemCode')" />
                        <x-ui.input id="attendance-allowance-effective-from" type="date" wire:model="allowanceEffectiveFrom" label="{{ __('Effective from') }}" required :error="$errors->first('allowanceEffectiveFrom')" />
                    </div>

                    @if (in_array($allowanceConditionPreset, ['min_worked', 'min_worked_and_after'], true))
                        <x-ui.input id="attendance-allowance-min-worked" type="number" min="0" max="1440" wire:model="allowanceMinWorkedMinutes" label="{{ __('Minimum worked minutes') }}" :error="$errors->first('allowanceMinWorkedMinutes')" />
                    @endif
                    @if (in_array($allowanceConditionPreset, ['clock_out_after', 'clock_out_window', 'min_worked_and_after'], true))
                        <x-ui.input id="attendance-allowance-clock-out-after" type="time" wire:model="allowanceClockOutAfter" label="{{ __('Clock-out after') }}" :error="$errors->first('allowanceClockOutAfter')" />
                    @endif
                    @if ($allowanceConditionPreset === 'clock_out_window')
                        <x-ui.input id="attendance-allowance-clock-out-before" type="time" wire:model="allowanceClockOutBefore" label="{{ __('Clock-out before') }}" :error="$errors->first('allowanceClockOutBefore')" />
                    @endif
                </div>

                <div class="space-y-4 rounded-2xl border border-border-default p-card-inner">
                    <x-ui.select id="attendance-allowance-policy" wire:model="allowancePolicyGroupId" label="{{ __('Policy group') }}" :error="$errors->first('allowancePolicyGroupId')">
                        <option value="">{{ __('Available to any policy') }}</option>
                        @foreach ($policyGroups as $group)
                            <option value="{{ $group->id }}">{{ $group->code }} - {{ $group->name }}</option>
                        @endforeach
                    </x-ui.select>
                    <p class="text-xs text-muted">
                        {{ __('Cannot find the policy you need?') }}
                        <a href="{{ route('people.attendance.policy-studio.library') }}" class="text-accent hover:underline">{{ __('Open Policy Studio') }}</a>
                    </p>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                        <x-ui.select id="attendance-allowance-type" wire:model="allowanceType" label="{{ __('Type') }}" :error="$errors->first('allowanceType')">
                            <option value="daily">{{ __('Daily') }}</option>
                            <option value="monthly">{{ __('Monthly') }}</option>
                        </x-ui.select>
                        <x-ui.select id="attendance-allowance-status" wire:model="allowanceStatus" label="{{ __('Status') }}" :error="$errors->first('allowanceStatus')">
                            <option value="active">{{ __('Active') }}</option>
                            <option value="inactive">{{ __('Inactive') }}</option>
                        </x-ui.select>
                    </div>
                    <x-ui.select id="attendance-allowance-resolution" wire:model="allowanceResolutionMethod" label="{{ __('If more than one row matches') }}" :error="$errors->first('allowanceResolutionMethod')">
                        <option value="sum">{{ __('Sum') }}</option>
                        <option value="min">{{ __('Minimum') }}</option>
                        <option value="max">{{ __('Maximum') }}</option>
                    </x-ui.select>
                </div>
            </div>

            <div class="flex justify-end">
                <x-ui.button type="submit" variant="primary" :disabled="! $canManage">
                    <x-icon name="heroicon-o-check-circle" class="h-4 w-4" />
                    {{ $editingAllowanceRuleId === null ? __('Create rule') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.card>

    <x-ui.card>
        <div class="flex items-center justify-between gap-3">
            <h3 class="text-base font-semibold text-ink">{{ __('Configured allowance rules') }}</h3>
            <span class="text-xs text-muted">{{ trans_choice(':count rule|:count rules', $allowanceRules->count(), ['count' => $allowanceRules->count()]) }}</span>
        </div>
        <div class="mt-4 space-y-3">
            @forelse ($allowanceRules as $rule)
                <div class="rounded-2xl border border-border-default p-3" wire:key="allowance-rule-{{ $rule->id }}">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <div class="font-medium text-ink">{{ $rule->code }} - {{ $rule->name }}</div>
                            <div class="mt-1 text-xs text-muted">{{ __('Policy: :policy / Pay item: :code', ['policy' => $rule->policyGroup?->code ?? __('Any'), 'code' => $rule->payroll_pay_item_code ?? '-']) }}</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-ui.badge :variant="$rule->status === 'active' ? 'success' : 'warning'">{{ __(ucfirst($rule->status)) }}</x-ui.badge>
                            <x-ui.button type="button" size="sm" variant="secondary" wire:click="editAllowanceRule({{ $rule->id }})">{{ __('Edit') }}</x-ui.button>
                            <x-ui.button type="button" size="sm" variant="danger" wire:click="deleteAllowanceRule({{ $rule->id }})" wire:confirm="{{ __('Delete this allowance rule?') }}">{{ __('Delete') }}</x-ui.button>
                        </div>
                    </div>
                    <div class="mt-3 grid gap-2 text-xs text-muted sm:grid-cols-3">
                        <div>{{ __('Type: :type', ['type' => __(ucfirst($rule->allowance_type))]) }}</div>
                        <div>{{ __('Amount: :amount', ['amount' => $rule->condition_rows[0]['amount'] ?? '-']) }}</div>
                        <div>{{ __('Effective: :date', ['date' => $rule->effective_from?->format('Y-m-d') ?? '-']) }}</div>
                    </div>
                </div>
            @empty
                <p class="text-sm text-muted">{{ __('No allowance rules configured yet. Create the first rule on the left, then validate the policy in Policy Studio.') }}</p>
            @endforelse
        </div>
    </x-ui.card>
</div>
