<div>
    <x-slot name="title">{{ __('Language & Region') }}</x-slot>

    <div class="space-y-section-gap">
        <x-ui.page-header
            :title="__('Language & Region')"
            :subtitle="__('Set the application locale used for regional formatting and default translation behavior.')"
        />

        @if (session('locale-status'))
            <x-ui.alert variant="success">
                {{ session('locale-status') }}
            </x-ui.alert>
        @endif

        @if (! $current['confirmed'])
            <x-ui.alert variant="warning">
                @if ($current['source_code'] === 'licensee_address')
                    {{ __('The current locale :locale was inferred from the licensee address and still needs confirmation.', ['locale' => $current['locale']]) }}
                @else
                    {{ __('The application is using a default locale because no confirmed locale has been set yet.') }}
                @endif
            </x-ui.alert>
        @endif

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
            <x-ui.card>
                <h3 class="mb-3 text-sm font-medium text-ink">{{ __('Current State') }}</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Effective locale') }}</dt>
                        <dd class="text-right text-ink">
                            <div>{{ $current['label'] }}</div>
                            <div class="tabular-nums text-xs text-muted">{{ $current['locale'] }}</div>
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Translation language') }}</dt>
                        <dd class="tabular-nums text-ink">{{ $current['language'] }}</dd>
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
                    @if ($current['inferred_country'])
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-muted">{{ __('Inferred country') }}</dt>
                            <dd class="tabular-nums text-ink">{{ $current['inferred_country'] }}</dd>
                        </div>
                    @endif
                </dl>
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

            <x-ui.card>
                <h3 class="mb-3 text-sm font-medium text-ink">{{ __('Regional Context') }}</h3>
                <p class="mb-3 text-xs text-muted">
                    {{ __('Read-only settings configured elsewhere.') }}
                </p>
                <dl class="space-y-2 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Company timezone') }}</dt>
                        <dd class="tabular-nums text-ink">
                            @if ($context['company_timezone_explicit'])
                                {{ $context['company_timezone'] }}
                            @else
                                <span class="text-status-warning">{{ __('Not set') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Currency') }}</dt>
                        <dd class="tabular-nums text-ink">{{ strtoupper($context['currency_code']) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-muted">{{ __('Language') }}</dt>
                        <dd class="tabular-nums text-ink">{{ $context['language'] }}</dd>
                    </div>
                </dl>
            </x-ui.card>
        </div>

        <x-ui.card>
            <form wire:submit="save" class="max-w-lg space-y-4">
                <x-ui.combobox
                    id="system-locale"
                    wire:model.live="selectedLocale"
                    :label="__('Locale')"
                    :placeholder="__('Search locale...')"
                    :options="$localeOptions"
                    :error="$errors->first('selectedLocale')"
                />

                @if ($bootstrap['country_iso'])
                    <p class="text-xs text-muted">
                        @if ($bootstrap['suggested_locale'])
                            {{ __('Locale :locale was inferred from the licensee address (:country). Confirm it if it is correct, or choose another locale.', ['locale' => $bootstrap['suggested_locale'], 'country' => $bootstrap['country_name'] ?: $bootstrap['country_iso']]) }}
                        @else
                            {{ __('The licensee address country :country is available, but BLB does not have a supported default locale mapping for it yet.', ['country' => $bootstrap['country_name'] ?: $bootstrap['country_iso']]) }}
                        @endif
                    </p>
                @else
                    <p class="text-xs text-muted">
                        {{ __('No licensee address country is available yet, so BLB cannot infer a default locale automatically.') }}
                    </p>
                @endif

                <div>
                    <x-ui.button type="submit" variant="primary" class="whitespace-nowrap">
                        {{ $current['confirmed'] ? __('Save locale') : __('Confirm locale') }}
                    </x-ui.button>
                </div>
            </form>
        </x-ui.card>
    </div>
</div>
