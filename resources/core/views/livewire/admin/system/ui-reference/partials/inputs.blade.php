<div class="space-y-section-gap">
    <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Choice Guidance')"
                    component="<code>x-ui.card</code>"
                >
                    {{ __('Choose the simplest control that still supports the user task. Searchable controls are not automatically better.') }}
                </x-ui.catalog-section>

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
                <x-ui.catalog-section
                    :title="__('Live State')"
                    component="<code>Livewire</code>"
                >
                    {{ __('The controls on this page are interactive. Compare the resulting values while you type and switch patterns.') }}
                </x-ui.catalog-section>

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
                        <dt class="text-muted">{{ __('Country combobox') }}</dt>
                        <dd class="text-right text-ink">{{ $countryComboboxValue }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Checkbox') }}</dt>
                        <dd class="text-right text-ink">{{ $checkboxValue ? __('Enabled') : __('Disabled') }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Radio') }}</dt>
                        <dd class="text-right text-ink">{{ $radioValue }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Datetime') }}</dt>
                        <dd class="text-right text-ink">{{ $dateValue }}</dd>
                    </div>
                </dl>
            </div>
        </x-ui.card>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Text and Long-Form Inputs')"
                    component="<code>x-ui.input</code>, <code>x-ui.search-input</code>, <code>x-ui.textarea</code>"
                >
                    {{ __('Use the help prop for short, always-visible field guidance. Keep it subdued and one sentence; longer explanations belong in page help or documentation.') }}
                </x-ui.catalog-section>

                <x-ui.input
                    id="ui-reference-text-input"
                    wire:model.live="textValue"
                    :label="__('Text Input')"
                    :placeholder="__('Enter a short operational label')"
                    :help="__('Short help stays visible and subdued. Keep it to one sentence.')"
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
                    :help="__('Do not truncate help copy. If it cannot fit here, rewrite it shorter or move it to page help.')"
                    rows="4"
                />
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Choice Controls')"
                    component="<code>x-ui.segmented-control</code>, <code>x-ui.select</code>, <code>x-ui.combobox</code>, <code>x-ui.checkbox</code>, <code>x-ui.radio</code>"
                >
                    {{ __('Use segmented controls for short peer choices, selects and comboboxes for longer lists, and checkbox or radio controls when each option needs more explanation.') }}
                </x-ui.catalog-section>

                <div class="space-y-1" x-data="{ displayMode: 'both' }">
                    <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Segmented Control') }}</div>
                    <x-ui.segmented-control
                        :options="[
                            ['value' => 'both', 'label' => __('Both')],
                            ['value' => 'dial', 'label' => __('Dial')],
                            ['value' => 'strip', 'label' => __('Strip')],
                        ]"
                        value="both"
                        :label="__('Display mode')"
                        size="md"
                        full-width
                        x-model="displayMode"
                    />
                    <x-ui.field-help :hint="__('Use for compact mutually exclusive choices when every option can stay visible.')" />
                </div>

                <x-ui.select
                    id="ui-reference-select"
                    wire:model.live="selectValue"
                    :label="__('Select')"
                    :help="__('Use when the valid options are fixed and can be scanned quickly.')"
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

                <x-ui.country-combobox
                    id="ui-reference-country-combobox"
                    wire:model.live="countryComboboxValue"
                    :label="__('Country combobox')"
                    :help="__('Single source for country pickers — GeoNames-backed; stores the 2-letter ISO code. Use everywhere a country is chosen.')"
                />

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="space-y-1">
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Checkbox') }}</div>
                        <x-ui.checkbox
                            id="ui-reference-checkbox"
                            wire:model.live="checkboxValue"
                            :label="__('Use compact density by default')"
                            :help="__('Use checkbox help for binary settings that need one sentence of context.')"
                        />
                    </div>

                    <div class="space-y-1">
                        <div class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Radio Group') }}</div>
                        <div class="space-y-2 rounded-2xl border border-border-default bg-surface-card p-4">
                            <x-ui.radio
                                id="ui-reference-radio-select"
                                name="ui-reference-radio"
                                wire:model.live="radioValue"
                                value="select"
                                :label="__('Prefer select')"
                                :help="__('Best for short fixed lists.')"
                            />
                            <x-ui.radio
                                id="ui-reference-radio-combobox"
                                name="ui-reference-radio"
                                wire:model.live="radioValue"
                                value="combobox"
                                :label="__('Prefer combobox')"
                                :help="__('Best when options need search.')"
                            />
                            <x-ui.radio
                                id="ui-reference-radio-text"
                                name="ui-reference-radio"
                                wire:model.live="radioValue"
                                value="text"
                                :label="__('Prefer free text')"
                                :help="__('Use only when values are open-ended.')"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Time Input')"
                    component="<code>x-ui.input</code> <code>type=&quot;datetime-local&quot;</code>, <code>x-ui.time-input</code>"
                >
                    {{ __('Native datetime-local for calendar pickers; pill-shaped time-input for HH:MM. Step buttons on time-input are optional.') }}
                </x-ui.catalog-section>

                <x-ui.input
                    id="ui-reference-datetime"
                    type="datetime-local"
                    wire:model.live="dateValue"
                    :label="__('Datetime')"
                />

                <div class="space-y-6" x-data="{ shiftStart: 480, shiftStartHhmm: '08:00',
                    stepTime(f,d){ this.shiftStart=((this.shiftStart+d*5)%1440+1440)%1440; this.shiftStartHhmm=String(Math.floor(this.shiftStart/60)).padStart(2,'0')+':'+String(this.shiftStart%60).padStart(2,'0') },
                    parseTime(f,v){ const p=v.split(':'); const h=+p[0]; const m=+(p[1]||0); if(!isNaN(h)){this.shiftStart=(h*60+m+1440)%1440; this.shiftStartHhmm=String(Math.floor(this.shiftStart/60)).padStart(2,'0')+':'+String(this.shiftStart%60).padStart(2,'0')} } }">
                    <div>
                        <p class="text-[10.5px] font-semibold uppercase tracking-widest text-muted mb-3">{{ __('Time — native picker') }}</p>
                        <x-ui.time-input field="shiftStart" />
                    </div>
                    <div>
                        <p class="text-[10.5px] font-semibold uppercase tracking-widest text-muted mb-3">{{ __('Time — with steps') }}</p>
                        <x-ui.time-input field="shiftStart" :with-steps="true" />
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div class="space-y-4">
                <x-ui.catalog-section
                    :title="__('Secret Input')"
                    component="<code>x-ui.secret-input</code>"
                >
                    {!! __('Credentials and integration secrets. <code>:show-reveal-button</code> on loads and reveals saved values; off keeps a fixed mask only.') !!}
                </x-ui.catalog-section>

                <div class="space-y-8">
                    @foreach ($secretInputVariants as $variant)
                        <div class="space-y-3">
                            <div>
                                <div class="text-sm font-medium text-ink">{{ __($variant['title']) }}</div>
                                <p class="mt-1 text-xs text-muted">{!! __($variant['description']) !!}</p>
                            </div>

                            <x-ui.secret-input
                                :id="$variant['id']"
                                :label="__($variant['label'])"
                                :value="$variant['value']"
                                :placeholder="$variant['placeholder'] !== '' ? __($variant['placeholder']) : ''"
                                :help="$variant['help'] !== '' ? __($variant['help']) : ''"
                                :error="$variant['error']"
                                :required="$variant['required']"
                                :has-value="$variant['hasValue']"
                                :show-reveal-button="$variant['showRevealButton']"
                                :saved-mask="$variant['savedMask']"
                            />

                            <p class="text-xs text-muted font-mono break-all">{{ $variant['code'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-ui.card>
    </div>
</div>
