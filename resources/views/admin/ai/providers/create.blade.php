<x-layouts.app :title="__('Create AI Provider')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Create AI Provider')" />

        <x-ui.card>
            <form method="POST" action="{{ route('admin.ai.providers.store') }}" class="space-y-3">
                @csrf
                <x-ui.input name="name" :label="__('Internal Name')" value="{{ old('name') }}" required />
                <x-ui.input name="display_name" :label="__('Display Name')" value="{{ old('display_name') }}" required />
                <x-ui.input name="base_url" :label="__('Base URL')" value="{{ old('base_url') }}" required />
                <x-ui.input name="api_key" :label="__('API Key')" value="{{ old('api_key') }}" required />
                <label class="inline-flex items-center gap-2 text-sm text-ink"><input type="checkbox" name="is_active" value="1" checked class="rounded border-border-input text-accent focus:ring-accent">{{ __('Active') }}</label>
                <div class="flex gap-2"><x-ui.button type="submit">{{ __('Create') }}</x-ui.button><x-ui.button as="a" variant="ghost" href="{{ route('admin.ai.providers.index') }}">{{ __('Cancel') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.app>
