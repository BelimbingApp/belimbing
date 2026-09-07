@include('livewire.settings.partials.fields-grid', ['group' => $group])

@php($outstanding = $this->outstandingIdentityWork)
@if (($outstanding['offers'] ?? 0) > 0 || ($outstanding['unapplied'] ?? 0) > 0)
    <div class="mt-5 rounded-md border border-status-danger-border bg-status-danger-subtle p-4">
        <p class="text-sm text-ink">
            {{ __('Changing instance ID or role while outstanding transfer work remains will orphan published offers and unapplied packages.') }}
        </p>
        <p class="mt-2 text-sm text-muted">
            {{ trans_choice(':count available offer|:count available offers', $outstanding['offers'], ['count' => $outstanding['offers']]) }}
            ·
            {{ trans_choice(':count unapplied package|:count unapplied packages', $outstanding['unapplied'], ['count' => $outstanding['unapplied']]) }}
        </p>
        <label class="mt-3 inline-flex items-center gap-2 text-sm text-ink">
            <input type="checkbox" wire:model.live="confirmIdentityChange" class="rounded border-input">
            <span>{{ __('I understand — change identity anyway') }}</span>
        </label>
        @error('confirmIdentityChange')
            <p class="mt-2 text-sm text-status-danger">{{ $message }}</p>
        @enderror
    </div>
@endif
