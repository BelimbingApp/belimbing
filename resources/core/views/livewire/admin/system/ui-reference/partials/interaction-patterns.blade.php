<div class="space-y-section-gap">
    <x-ui.card>
        <div class="space-y-4">
            <x-ui.catalog-section
                :title="__('Field-Level Edit-in-Place')"
                component="<code>x-ui.edit-in-place.text</code>, <code>x-ui.edit-in-place.select</code>, <code>x-ui.edit-in-place.textarea</code>"
            >
                {{ __('Use this pattern on detail pages when the user should review facts first, then update one field without navigating to a separate edit form.') }}
            </x-ui.catalog-section>

            <div class="grid gap-3 md:grid-cols-3">
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('Read First') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('Values render as normal detail text. The pencil appears on hover or focus as an affordance, not as visual clutter.') }}</p>
                </div>
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('One Field at a Time') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('Clicking a value swaps only that value into an input, select, or textarea. The rest of the page stays readable.') }}</p>
                </div>
                <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                    <div class="text-sm font-medium text-ink">{{ __('Keyboard Contract') }}</div>
                    <p class="mt-1 text-xs text-muted">{{ __('Enter saves short text, blur commits, and Escape restores the last saved value.') }}</p>
                </div>
            </div>
        </div>
    </x-ui.card>

    <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Detail Facts Example')"
                    component="<code>x-ui.edit-in-place.*</code>"
                >
                    {{ __('This mirrors Company, Address, and Inventory detail pages: compact facts, direct field edits, and page-owned Livewire save methods.') }}
                </x-ui.catalog-section>

                <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <x-ui.edit-in-place.text
                        :label="__('Title')"
                        :value="$editInPlaceTitle"
                        field="editInPlaceTitle"
                        save-method="saveReferenceField"
                    />

                    <x-ui.edit-in-place.select
                        :label="__('Review Status')"
                        :value="$editInPlaceStatus"
                        field="editInPlaceStatus"
                        save-method="saveReferenceField"
                    >
                        <x-slot name="read">
                            <x-ui.badge :variant="match($editInPlaceStatus) {
                                'approved' => 'success',
                                'blocked' => 'danger',
                                'review' => 'warning',
                                default => 'default',
                            }">
                                {{ __(Illuminate\Support\Str::headline($editInPlaceStatus)) }}
                            </x-ui.badge>
                        </x-slot>

                        @foreach ($statusOptions as $option)
                            <option value="{{ $option['value'] }}">{{ __($option['label']) }}</option>
                        @endforeach
                        <option value="blocked">{{ __('Blocked') }}</option>
                    </x-ui.edit-in-place.select>
                </dl>

                <dl class="border-t border-border-default pt-4">
                    <x-ui.edit-in-place.textarea
                        :label="__('Operator Notes')"
                        :value="$editInPlaceNotes"
                        field="editInPlaceNotes"
                        save-method="saveReferenceField"
                        :empty="__('No notes captured yet.')"
                        rows="5"
                    />
                </dl>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-3">
                <x-ui.catalog-section
                    :title="__('When to Use')"
                    component="<code>detail pages</code>"
                />

                <div class="space-y-3 text-sm text-muted">
                    <p>{{ __('Use field-level edit-in-place for low-risk factual attributes where the saved value is obvious from the field itself.') }}</p>
                    <p>{{ __('Prefer a full form when edits are multi-step, destructive, require confirmation, or depend on several fields changing together.') }}</p>
                    <p>{{ __('Keep authorization, validation, persistence, and data conversion in the Livewire page. The UI component owns interaction behavior only.') }}</p>
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
