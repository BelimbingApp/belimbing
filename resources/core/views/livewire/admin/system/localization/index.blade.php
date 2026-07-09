<div>
    <x-slot name="title">{{ __('Language & Region') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Language & Region')"
            :subtitle="__('Set the application locale used for regional formatting and default translation behavior.')"
        >
            <x-slot name="actions">
                <x-ui.record-history
                    :title="__('History for Language & Region')"
                    :subjects="$auditSubjects"
                    source-capability="admin.system.localization.manage"
                />
            </x-slot>
        </x-ui.page-header>

        @if (! $current['confirmed'])
            <x-ui.alert variant="warning">
                @if ($current['source_code'] === 'licensee_address')
                    {{ __('The current locale :locale was inferred from the licensee address and still needs confirmation.', ['locale' => $current['locale']]) }}
                @else
                    {{ __('The application is using a default locale because no confirmed locale has been set yet.') }}
                @endif
            </x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <x-ui.card>
                <h3 class="mb-3 text-sm font-medium text-ink">{{ __('Configuration') }}</h3>
                <div class="space-y-3 text-sm">
                    <x-ui.edit-in-place.combobox
                        id="system-locale"
                        wire:model.live="selectedLocale"
                        :label="__('Effective locale')"
                        :value="$current['locale']"
                        :display="$current['label']"
                        :options="$localeOptions"
                        :placeholder="__('Search locale...')"
                        :error="$errors->first('selectedLocale')"
                        :help="$localeHelp"
                    />

                    <x-ui.edit-in-place.combobox
                        id="company-timezone"
                        wire:model.live="companyTimezone"
                        :label="__('Company timezone')"
                        :value="$companyTimezone"
                        :empty="__('Not set')"
                        :placeholder="__('Search timezone...')"
                        :options="$timezoneOptions"
                        :help="__('Default timezone for dates and times in Company mode.')"
                        :error="$errors->first('companyTimezone')"
                    />

                    <dl class="space-y-2 pt-3 border-t border-border-default">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">{{ __('Translation language') }}</dt>
                            <dd class="tabular-nums text-ink">{{ $current['language'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">{{ __('Currency') }}</dt>
                            <dd class="tabular-nums text-ink">{{ strtoupper($currency_code) }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">{{ __('Source') }}</dt>
                            <dd class="text-right text-ink">{{ $current['source'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">{{ __('Confirmation') }}</dt>
                            <dd>
                                <x-ui.badge :variant="$current['confirmed'] ? 'success' : 'warning'">
                                    {{ $current['confirmed'] ? __('Confirmed') : __('Pending confirmation') }}
                                </x-ui.badge>
                            </dd>
                        </div>
                    </dl>
                </div>
            </x-ui.card>

            <x-ui.card>
                <h3 class="mb-3 text-sm font-medium text-ink">{{ __('Preview') }}</h3>
                <p class="mb-3 text-xs text-muted">
                    {{ __('Previewing locale :locale.', ['locale' => $preview['locale']]) }}
                </p>
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Date') }}</dt>
                        <dd class="tabular-nums text-ink">
                            @if ($preview['local_mode'])
                                <time
                                    datetime="{{ $preview['sample_iso'] }}"
                                    data-format="date"
                                    data-locale="{{ $preview['locale'] }}"
                                    x-data
                                    x-init="
                                        const apply = () => {
                                            if (window.blbMountDateTimeElement) {
                                                window.blbMountDateTimeElement($el, () => ({}));
                                                return;
                                            }

                                            requestAnimationFrame(apply);
                                        };

                                        apply();
                                    "
                                    x-effect="window.blbFormatDateTimeElement?.($el)"
                                >{{ $preview['date'] }}</time>
                            @else
                                {{ $preview['date'] }}
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Time') }}</dt>
                        <dd class="tabular-nums text-ink">
                            @if ($preview['local_mode'])
                                <time
                                    datetime="{{ $preview['sample_iso'] }}"
                                    data-format="time"
                                    data-locale="{{ $preview['locale'] }}"
                                    x-data
                                    x-init="
                                        const apply = () => {
                                            if (window.blbMountDateTimeElement) {
                                                window.blbMountDateTimeElement($el, () => ({}));
                                                return;
                                            }

                                            requestAnimationFrame(apply);
                                        };

                                        apply();
                                    "
                                    x-effect="window.blbFormatDateTimeElement?.($el)"
                                >{{ $preview['time'] }}</time>
                            @else
                                {{ $preview['time'] }}
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Datetime') }}</dt>
                        <dd class="tabular-nums text-ink">
                            @if ($preview['local_mode'])
                                <time
                                    datetime="{{ $preview['sample_iso'] }}"
                                    data-format="datetime"
                                    data-locale="{{ $preview['locale'] }}"
                                    x-data
                                    x-init="
                                        const apply = () => {
                                            if (window.blbMountDateTimeElement) {
                                                window.blbMountDateTimeElement($el, () => ({}));
                                                return;
                                            }

                                            requestAnimationFrame(apply);
                                        };

                                        apply();
                                    "
                                    x-effect="window.blbFormatDateTimeElement?.($el)"
                                >{{ $preview['datetime'] }}</time>
                            @else
                                {{ $preview['datetime'] }}
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Number') }}</dt>
                        <dd class="tabular-nums text-ink">{{ $preview['number'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Currency') }}</dt>
                        <dd class="tabular-nums text-ink">
                            <div>{{ $preview['currency'] }}</div>
                            <div class="text-xs text-muted">{{ __('Sample currency: :code', ['code' => $preview['currency_code']]) }}</div>
                        </dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>
    </div>
</div>
