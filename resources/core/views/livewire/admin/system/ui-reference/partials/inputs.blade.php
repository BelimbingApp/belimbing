<div class="space-y-section-gap">
    <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <x-ui.card>
            <div class="space-y-4">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Choice Guidance') }}</h2>
                    <p class="text-xs text-muted">{{ __('Choose the simplest control that still supports the user task. Searchable controls are not automatically better.') }}</p>
                </div>

                <div class="grid gap-3 md:grid-cols-3">
                    <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                        <div class="text-sm font-medium text-ink">{{ __('Select') }}</div>
                        <p class="mt-1 text-xs text-muted">{{ __('Use for short, stable option lists that can be scanned quickly.') }}</p>
                    </div>
                    <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                        <div class="text-sm font-medium text-ink">{{ __('Combobox') }}</div>
                        <p class="mt-1 text-xs text-muted">{{ __('Use when options are longer, searchable, or exceed quick visual scanning.') }}</p>
                    </div>
                    <div class="rounded-2xl border border-border-default bg-surface-card p-4">
                        <div class="text-sm font-medium text-ink">{{ __('Free Text') }}</div>
                        <p class="mt-1 text-xs text-muted">{{ __('Use only when the value is truly open-ended and not governed by a standard list.') }}</p>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-3">
                <div>
                    <h2 class="text-sm font-medium text-ink">{{ __('Live State') }}</h2>
                    <p class="text-xs text-muted">{{ __('The controls on this page are interactive. Compare the resulting values while you type and switch patterns.') }}</p>
                </div>

                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Text input') }}</dt>
                        <dd class="text-right text-ink">{{ $textValue }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Search') }}</dt>
                        <dd class="text-right text-ink">{{ $searchValue !== '' ? $searchValue : __('Empty') }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Select') }}</dt>
                        <dd class="text-right text-ink">{{ $selectValue }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Combobox') }}</dt>
                        <dd class="text-right text-ink">{{ $comboboxValue }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Editable combobox') }}</dt>
                        <dd class="text-right text-ink">{{ $editableComboboxValue }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Checkbox') }}</dt>
                        <dd class="text-right text-ink">{{ $checkboxValue ? __('Enabled') : __('Disabled') }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Radio') }}</dt>
                        <dd class="text-right text-ink">{{ $radioValue }}</dd>
                    </div>
                </dl>
            </div>
        </x-ui.card>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <h2 class="text-sm font-medium text-ink">{{ __('Text and Long-Form Inputs') }}</h2>

                <x-ui.input
                    id="ui-reference-text-input"
                    wire:model.live="textValue"
                    :label="__('Text Input')"
                    :placeholder="__('Enter a short operational label')"
                />

                <div class="space-y-1">
                    <label for="ui-reference-search-input" class="block text-[11px] uppercase tracking-wider font-semibold text-muted">
                        {{ __('Search Input') }}
                    </label>
                    <x-ui.search-input
                        id="ui-reference-search-input"
                        wire:model.live="searchValue"
                        :placeholder="__('Search records...')"
                    />
                </div>

                <x-ui.textarea
                    id="ui-reference-textarea"
                    wire:model.live="textareaValue"
                    :label="__('Textarea')"
                    rows="4"
                />

                <x-ui.input
                    id="ui-reference-datetime"
                    type="datetime-local"
                    wire:model.live="dateValue"
                    :label="__('Datetime Input')"
                />
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <h2 class="text-sm font-medium text-ink">{{ __('Choice Controls') }}</h2>

                <x-ui.select
                    id="ui-reference-select"
                    wire:model.live="selectValue"
                    :label="__('Select')"
                >
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ __($option['label']) }}</option>
                    @endforeach
                </x-ui.select>

                <x-ui.combobox
                    id="ui-reference-combobox"
                    wire:model.live="comboboxValue"
                    :label="__('Combobox')"
                    :options="$comboboxOptions"
                    :placeholder="__('Search or select...')"
                />

                <x-ui.combobox
                    id="ui-reference-editable-combobox"
                    wire:model.live="editableComboboxValue"
                    :label="__('Editable Combobox')"
                    :options="$comboboxOptions"
                    :placeholder="__('Select or type a custom label...')"
                    editable
                />

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="space-y-2">
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Checkbox') }}</div>
                        <x-ui.checkbox
                            id="ui-reference-checkbox"
                            wire:model.live="checkboxValue"
                            :label="__('Use compact density by default')"
                        />
                    </div>

                    <div class="space-y-2">
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Radio Group') }}</div>
                        <div class="space-y-2 rounded-2xl border border-border-default bg-surface-card p-4">
                            <x-ui.radio id="ui-reference-radio-select" name="ui-reference-radio" wire:model.live="radioValue" value="select" :label="__('Prefer select')" />
                            <x-ui.radio id="ui-reference-radio-combobox" name="ui-reference-radio" wire:model.live="radioValue" value="combobox" :label="__('Prefer combobox')" />
                            <x-ui.radio id="ui-reference-radio-text" name="ui-reference-radio" wire:model.live="radioValue" value="text" :label="__('Prefer free text')" />
                        </div>
                    </div>
                </div>
            </div>
        </x-ui.card>
    </div>
</div>

