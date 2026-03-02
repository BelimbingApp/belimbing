<x-layouts.app :title="$provider->display_name">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="$provider->display_name" :subtitle="$provider->name" />

        @if (session('success'))<x-ui.alert variant="success">{{ session('success') }}</x-ui.alert>@endif

        <x-ui.card>
            <form method="POST" action="{{ route('admin.ai.providers.update', $provider) }}" class="space-y-3">
                @csrf
                @method('PUT')
                <x-ui.input name="display_name" :label="__('Display Name')" value="{{ old('display_name', $provider->display_name) }}" required />
                <x-ui.input name="base_url" :label="__('Base URL')" value="{{ old('base_url', $provider->base_url) }}" required />
                <x-ui.input name="api_key" :label="__('API Key (leave empty to keep current)')" value="" />
                <label class="inline-flex items-center gap-2 text-sm text-ink"><input type="checkbox" name="is_active" value="1" @checked($provider->is_active) class="rounded border-border-input text-accent focus:ring-accent">{{ __('Active') }}</label>
                <div class="flex gap-2"><x-ui.button type="submit">{{ __('Save') }}</x-ui.button><x-ui.button as="a" variant="ghost" href="{{ route('admin.ai.providers.index') }}">{{ __('Back') }}</x-ui.button></div>
            </form>

            <form method="POST" action="{{ route('admin.ai.providers.destroy', $provider) }}" class="mt-4">
                @csrf
                @method('DELETE')
                <x-ui.button type="submit" variant="danger">{{ __('Delete Provider') }}</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card>
            <h3 class="text-sm font-medium text-ink mb-3">{{ __('Models') }}</h3>
            <div class="overflow-x-auto -mx-card-inner px-card-inner">
                <table class="min-w-full divide-y divide-border-default text-sm">
                    <thead class="bg-surface-subtle/80"><tr><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Model') }}</th><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Context') }}</th><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Max Tokens') }}</th><th class="px-table-cell-x py-table-header-y text-left text-[11px] font-semibold text-muted uppercase tracking-wider">{{ __('Status') }}</th></tr></thead>
                    <tbody class="bg-surface-card divide-y divide-border-default">
                        @forelse($provider->models as $model)
                            <tr><td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-ink">{{ $model->display_name }} <span class="text-xs text-muted font-mono">{{ $model->model_name }}</span></td><td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $model->context_window ?? '—' }}</td><td class="px-table-cell-x py-table-cell-y whitespace-nowrap text-sm text-muted tabular-nums">{{ $model->max_tokens ?? '—' }}</td><td class="px-table-cell-x py-table-cell-y whitespace-nowrap"><x-ui.badge :variant="$model->is_active ? 'success' : 'default'">{{ $model->is_active ? __('Active') : __('Inactive') }}</x-ui.badge></td></tr>
                        @empty
                            <tr><td colspan="4" class="px-table-cell-x py-6 text-center text-sm text-muted">{{ __('No models configured.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
