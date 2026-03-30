        <x-ui.card>
            <h3 class="text-[11px] uppercase tracking-wider font-semibold text-muted mb-4">{{ __('Company Details') }}</h3>

                <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div x-data="{ editing: false, val: '{{ addslashes($company->name) }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Name') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('name', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($company->name) }}'"
                                @blur="editing = false; $wire.saveField('name', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ addslashes($company->code ?? '') }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Code') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span class="font-mono" x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('code', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($company->code ?? '') }}'"
                                @blur="editing = false; $wire.saveField('code', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm font-mono border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ addslashes($company->legal_name ?? '') }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Legal Name') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('legal_name', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($company->legal_name ?? '') }}'"
                                @blur="editing = false; $wire.saveField('legal_name', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ $company->status }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Status') }}</dt>
                        <dd class="mt-0.5">
                            <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer">
                                <x-ui.badge :variant="match($company->status) {
                                    'active' => 'success',
                                    'suspended' => 'danger',
                                    'pending' => 'warning',
                                    default => 'default',
                                }">{{ ucfirst($company->status) }}</x-ui.badge>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <select
                                x-show="editing"
                                x-model="val"
                                @change="editing = false; $wire.saveStatus(val)"
                                @keydown.escape="editing = false; val = '{{ $company->status }}'"
                                @blur="editing = false"
                                class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            >
                                <option value="active">{{ __('Active') }}</option>
                                <option value="suspended">{{ __('Suspended') }}</option>
                                <option value="pending">{{ __('Pending') }}</option>
                                <option value="archived">{{ __('Archived') }}</option>
                            </select>
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ $company->legal_entity_type_id ?? '' }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Legal Entity Type') }}</dt>
                        <dd class="mt-0.5">
                            <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span class="text-sm text-ink">{{ $company->legalEntityType?->name ?? '-' }}</span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <select
                                x-show="editing"
                                x-model="val"
                                @change="editing = false; $wire.saveField('legal_entity_type_id', val || null)"
                                @keydown.escape="editing = false; val = '{{ $company->legal_entity_type_id ?? '' }}'"
                                @blur="editing = false"
                                class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            >
                                <option value="">{{ __('None') }}</option>
                                @foreach($legalEntityTypes as $type)
                                    <option value="{{ $type->id }}">{{ $type->name }}</option>
                                @endforeach
                            </select>
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ addslashes($company->registration_number ?? '') }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Registration Number') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('registration_number', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($company->registration_number ?? '') }}'"
                                @blur="editing = false; $wire.saveField('registration_number', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ addslashes($company->tax_id ?? '') }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Tax ID') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('tax_id', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($company->tax_id ?? '') }}'"
                                @blur="editing = false; $wire.saveField('tax_id', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ $company->jurisdiction ?? '' }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Jurisdiction') }}</dt>
                        <dd class="mt-0.5">
                            <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span class="text-sm text-ink">
                                    @if($company->jurisdiction)
                                        {{ $countries->firstWhere('iso', $company->jurisdiction)?->country ?? $company->jurisdiction }}
                                        <span class="text-muted">({{ $company->jurisdiction }})</span>
                                    @else
                                        -
                                    @endif
                                </span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <select
                                x-show="editing"
                                x-model="val"
                                @change="editing = false; $wire.saveField('jurisdiction', val || null)"
                                @keydown.escape="editing = false; val = '{{ $company->jurisdiction ?? '' }}'"
                                @blur="editing = false"
                                class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            >
                                <option value="">{{ __('None') }}</option>
                                @foreach($countries as $country)
                                    <option value="{{ $country->iso }}">{{ $country->country }} ({{ $country->iso }})</option>
                                @endforeach
                            </select>
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ addslashes($company->email ?? '') }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Email') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('email', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($company->email ?? '') }}'"
                                @blur="editing = false; $wire.saveField('email', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ addslashes($company->website ?? '') }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Website') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true; $nextTick(() => $refs.input.select())" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span x-text="val || '-'"></span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <input
                                x-show="editing"
                                x-ref="input"
                                x-model="val"
                                @keydown.enter="editing = false; $wire.saveField('website', val)"
                                @keydown.escape="editing = false; val = '{{ addslashes($company->website ?? '') }}'"
                                @blur="editing = false; $wire.saveField('website', val)"
                                type="text"
                                class="w-full px-1 -mx-1 py-0.5 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            />
                        </dd>
                    </div>
                    <div x-data="{ editing: false, val: '{{ $company->parent_id ?? '' }}' }">
                        <dt class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Parent Company') }}</dt>
                        <dd class="text-sm text-ink">
                            <div x-show="!editing" @click="editing = true" class="group flex items-center gap-1.5 cursor-pointer rounded px-1 -mx-1 py-0.5 hover:bg-surface-subtle">
                                <span>
                                    @if($company->parent)
                                        {{ $company->parent->name }}
                                    @else
                                        <span class="text-muted">{{ __('None') }}</span>
                                    @endif
                                </span>
                                <x-icon name="heroicon-o-pencil" class="w-3.5 h-3.5 text-muted opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                            <select
                                x-show="editing"
                                x-model="val"
                                @change="editing = false; $wire.saveParent(val ? parseInt(val, 10) : null)"
                                @keydown.escape="editing = false; val = '{{ $company->parent_id ?? '' }}'"
                                @blur="editing = false"
                                class="px-2 py-1 text-sm border border-accent rounded bg-surface-card text-ink focus:outline-none focus:ring-1 focus:ring-accent"
                            >
                                <option value="">{{ __('None') }}</option>
                                @foreach($parentCompanies as $parentCompany)
                                    <option value="{{ $parentCompany->id }}">{{ $parentCompany->name }}</option>
                                @endforeach
                            </select>
                        </dd>
                    </div>
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
