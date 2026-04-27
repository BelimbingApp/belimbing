        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Company Details') }}</h3>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <x-ui.edit-in-place.text :label="__('Name')" :value="$company->name" field="name" />
                    <x-ui.edit-in-place.text :label="__('Code')" :value="$company->code" field="code" monospace />
                    <x-ui.edit-in-place.text :label="__('Legal Name')" :value="$company->legal_name" field="legal_name" />
                    <x-ui.edit-in-place.select :label="__('Status')" :value="$company->status" save-method="saveStatus">
                        <x-slot name="read">
                            <x-ui.badge :variant="match($company->status) {
                                'active' => 'success',
                                'suspended' => 'danger',
                                'pending' => 'warning',
                                default => 'default',
                            }">{{ ucfirst($company->status) }}</x-ui.badge>
                        </x-slot>

                        <option value="active">{{ __('Active') }}</option>
                        <option value="suspended">{{ __('Suspended') }}</option>
                        <option value="pending">{{ __('Pending') }}</option>
                        <option value="archived">{{ __('Archived') }}</option>
                    </x-ui.edit-in-place.select>
                    <x-ui.edit-in-place.select
                        :label="__('Legal Entity Type')"
                        :value="$company->legal_entity_type_id"
                        field="legal_entity_type_id"
                        save-value="val || null"
                    >
                        <x-slot name="read">
                            <span class="text-sm text-ink">{{ $company->legalEntityType?->name ?? '-' }}</span>
                        </x-slot>

                        <option value="">{{ __('None') }}</option>
                        @foreach($legalEntityTypes as $type)
                            <option value="{{ $type->id }}">{{ $type->name }}</option>
                        @endforeach
                    </x-ui.edit-in-place.select>
                    <x-ui.edit-in-place.text :label="__('Registration Number')" :value="$company->registration_number" field="registration_number" />
                    <x-ui.edit-in-place.text :label="__('Tax ID')" :value="$company->tax_id" field="tax_id" />
                    <x-ui.edit-in-place.select
                        :label="__('Jurisdiction')"
                        :value="$company->jurisdiction"
                        field="jurisdiction"
                        save-value="val || null"
                    >
                        <x-slot name="read">
                            <span class="text-sm text-ink">
                                @if($company->jurisdiction)
                                    {{ $countries->firstWhere('iso', $company->jurisdiction)?->country ?? $company->jurisdiction }}
                                    <span class="text-muted">({{ $company->jurisdiction }})</span>
                                @else
                                    -
                                @endif
                            </span>
                        </x-slot>

                        <option value="">{{ __('None') }}</option>
                        @foreach($countries as $country)
                            <option value="{{ $country->iso }}">{{ $country->country }} ({{ $country->iso }})</option>
                        @endforeach
                    </x-ui.edit-in-place.select>
                    <x-ui.edit-in-place.text :label="__('Email')" :value="$company->email" field="email" type="email" />
                    <x-ui.edit-in-place.text :label="__('Website')" :value="$company->website" field="website" type="url" />
                    <x-ui.edit-in-place.select
                        :label="__('Parent Company')"
                        :value="$company->parent_id"
                        save-method="saveParent"
                        save-value="val ? parseInt(val, 10) : null"
                    >
                        <x-slot name="read">
                            <span class="text-sm text-ink">{{ $company->parent?->name ?? __('None') }}</span>
                        </x-slot>

                        <option value="">{{ __('None') }}</option>
                        @foreach($parentCompanies as $parentCompany)
                            <option value="{{ $parentCompany->id }}">{{ $parentCompany->name }}</option>
                        @endforeach
                    </x-ui.edit-in-place.select>
                </dl>

                <dl class="mt-4" x-data="{ adding: false, newItem: '' }">
                    <div>
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Business Activities') }}</dt>
                        <dd class="mt-0.5">
                            <p class="text-xs text-muted mb-1">{{ __('Industry, services, and business focus areas of this company.') }}</p>
                            <div class="flex flex-wrap items-center gap-2">
                                @forelse($company->scope_activities ?? [] as $index => $activity)
                                    @if(is_string($activity))
                                        <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium bg-surface-subtle text-ink border border-border-default group">
                                            {{ $activity }}
                                            <button
                                                wire:click="removeActivity({{ $index }})"
                                                class="text-muted hover:text-status-danger opacity-0 group-hover:opacity-100 transition-opacity"
                                                title="{{ __('Remove') }}"
                                            >&times;</button>
                                        </span>
                                    @endif
                                @empty
                                    <span class="text-sm text-muted" x-show="!adding">-</span>
                                @endforelse

                                <button
                                    x-show="!adding"
                                    @click="adding = true; $nextTick(() => $refs.newInput.focus())"
                                    class="inline-flex items-center gap-0.5 px-2 py-1 rounded-full text-xs text-muted hover:text-ink hover:bg-surface-subtle border border-dashed border-border-default transition-colors"
                                    title="{{ __('Add activity') }}"
                                >
                                    <x-icon name="heroicon-o-plus" class="w-3 h-3" />
                                    {{ __('Add') }}
                                </button>

                                <div x-show="adding" class="inline-flex items-center gap-1">
                                    <input
                                        x-ref="newInput"
                                        x-model="newItem"
                                        @keydown.enter="if (newItem.trim()) { $wire.addActivity(newItem.trim()); newItem = ''; } else { adding = false; }"
                                        @keydown.escape="adding = false; newItem = ''"
                                        @blur="if (newItem.trim()) { $wire.addActivity(newItem.trim()); newItem = ''; } adding = false;"
                                        type="text"
                                        placeholder="{{ __('e.g. manufacturing') }}"
                                        class="px-2 py-0.5 text-xs border border-accent rounded-full bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent w-40"
                                    />
                                </div>
                            </div>
                        </dd>
                    </div>
                </dl>

                @php
                    $metadataJson = $company->metadata ? json_encode($company->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
                @endphp

                <dl class="mt-4" x-data="{ editing: false, val: {{ $metadataJson ? '`' . addslashes($metadataJson) . '`' : "''" }} }">
                    <div>
                        <dt class="flex items-center gap-1.5">
                            <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Metadata') }}</span>
                            <button @click="editing = !editing; if (editing) $nextTick(() => $refs.textarea.focus())" class="group">
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-50 hover:opacity-100 transition-opacity" />
                            </button>
                        </dt>

                        <dd x-show="!editing" class="mt-1">
                            @if($company->metadata)
                                <pre class="text-sm text-ink bg-surface-subtle rounded-2xl p-3 overflow-x-auto">{{ $metadataJson }}</pre>
                            @else
                                <span class="text-sm text-muted">-</span>
                            @endif
                        </dd>

                        <dd x-show="editing" class="mt-1 space-y-2">
                            <textarea
                                x-ref="textarea"
                                x-model="val"
                                @keydown.escape="editing = false; val = {{ $metadataJson ? '`' . addslashes($metadataJson) . '`' : "''" }}"
                                rows="6"
                                class="w-full px-input-x py-input-y text-sm font-mono border border-accent rounded-2xl bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                                placeholder="{{ __('{"employee_count":120,"founded_year":2014}') }}"
                            ></textarea>
                            <div class="flex items-center gap-2">
                                <button @click="editing = false; $wire.saveMetadata(val)" class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-lg bg-accent text-accent-on hover:bg-accent-hover transition-colors">{{ __('Save') }}</button>
                                <button @click="editing = false; val = {{ $metadataJson ? '`' . addslashes($metadataJson) . '`' : "''" }}" class="inline-flex items-center px-3 py-1 text-xs font-medium rounded-lg hover:bg-surface-subtle text-muted transition-colors">{{ __('Cancel') }}</button>
                            </div>
                        </dd>
                    </div>
                </dl>
        </x-ui.card>
