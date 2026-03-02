<x-layouts.app :title="__('Address Details')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Address Details')">
            <x-slot name="actions">
                <x-ui.button variant="ghost" as="a" href="{{ route('admin.addresses.index') }}">
                    <x-icon name="heroicon-o-arrow-left" class="h-5 w-5" />
                    {{ __('Back to List') }}
                </x-ui.button>
            </x-slot>
        </x-ui.page-header>

        @if (session('success'))
            <x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>
        @endif

        <x-ui.card>
            <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Address Details') }}</h3>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ([
                    'label' => __('Label'),
                    'phone' => __('Phone'),
                    'line1' => __('Address Line 1'),
                    'line2' => __('Address Line 2'),
                    'line3' => __('Address Line 3'),
                ] as $field => $label)
                    <form method="POST" action="{{ route('admin.addresses.update-field', $address) }}" class="space-y-1">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="field" value="{{ $field }}">
                        <x-ui.input :name="'value'" :label="$label" :value="$address->{$field}" type="text" />
                        <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Save') }}</x-ui.button>
                    </form>
                @endforeach

                <form method="POST" action="{{ route('admin.addresses.update-field', $address) }}" class="space-y-1">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="field" value="verification_status">
                    <x-ui.select name="value" :label="__('Verification Status')">
                        <option value="unverified" @selected($address->verification_status === 'unverified')>{{ __('Unverified') }}</option>
                        <option value="suggested" @selected($address->verification_status === 'suggested')>{{ __('Suggested') }}</option>
                        <option value="verified" @selected($address->verification_status === 'verified')>{{ __('Verified') }}</option>
                    </x-ui.select>
                    <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Save') }}</x-ui.button>
                </form>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Geo Fields') }}</h3>
            <form method="POST" action="{{ route('admin.addresses.update-geo-field', $address) }}" class="space-y-6">
                @csrf
                @method('PATCH')

                @include('partials.address.geo-form', [
                    'countryIso' => old('country_iso', $address->country_iso),
                    'admin1Code' => old('admin1_code', $address->admin1_code),
                    'postcode' => old('postcode', $address->postcode),
                    'locality' => old('locality', $address->locality),
                    'countryOptions' => $countryOptions,
                    'admin1Options' => $admin1Options,
                    'namePrefix' => '',
                ])

                <x-ui.button type="submit" variant="primary">{{ __('Save Geo Fields') }}</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card>
            <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Provenance') }}</h3>
            <p class="mb-4 mt-0.5 text-xs text-muted">{{ __('Tracks where this address came from and how it was processed — useful for auditing data quality and imports.') }}</p>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ([
                    'source' => __('Source'),
                    'source_ref' => __('Source Reference'),
                    'raw_input' => __('Raw Input'),
                ] as $field => $label)
                    <form method="POST" action="{{ route('admin.addresses.update-field', $address) }}" class="space-y-1 {{ $field === 'raw_input' ? 'md:col-span-2' : '' }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="field" value="{{ $field }}">
                        @if ($field === 'raw_input')
                            <x-ui.textarea name="value" :label="$label" rows="4">{{ $address->{$field} }}</x-ui.textarea>
                        @else
                            <x-ui.input :name="'value'" :label="$label" :value="$address->{$field}" type="text" />
                        @endif
                        <x-ui.button type="submit" size="sm" variant="ghost">{{ __('Save') }}</x-ui.button>
                    </form>
                @endforeach

                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Parser Version') }}</dt>
                    <dd class="mt-0.5 text-sm text-ink">{{ $address->parser_version ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Parse Confidence') }}</dt>
                    <dd class="mt-0.5 text-sm text-ink">{{ $address->parse_confidence !== null ? $address->parse_confidence : '—' }}</dd>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted">
                {{ __('Linked Entities') }}
                <x-ui.badge>{{ $linkedEntities->count() }}</x-ui.badge>
            </h3>
            <p class="mb-4 mt-0.5 text-xs text-muted">{{ __('Companies, employees, or other records that use this address.') }}</p>

            <div class="-mx-card-inner overflow-x-auto px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80">
                        <tr>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Entity Type') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Name') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Kind') }}</th>
                            <th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Primary') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-default bg-surface-card">
                        @forelse ($linkedEntities as $entity)
                            <tr class="transition-colors hover:bg-surface-subtle/50">
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $entity->type }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $entity->model->name ?? $entity->model->id }}</td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">
                                    @if (is_array($entity->kind) && count($entity->kind) > 0)
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($entity->kind as $kind)
                                                <x-ui.badge variant="default">{{ ucfirst($kind) }}</x-ui.badge>
                                            @endforeach
                                        </div>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-table-cell-x py-table-cell-y text-sm text-muted">{{ $entity->is_primary ? __('Yes') : __('No') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-table-cell-x py-8 text-center text-sm text-muted">{{ __('No linked entities.') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
