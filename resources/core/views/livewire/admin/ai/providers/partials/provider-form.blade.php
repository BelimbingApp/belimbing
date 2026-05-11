<form wire:submit="saveProvider" class="space-y-4">
    @unless($isEditingProvider)
        <x-ui.select id="provider-template" wire:change="applyTemplate($event.target.value)" label="{{ __('Template') }}">
            <option value="">{{ __('Other provider') }}</option>
            @foreach($templateOptions as $tpl)
                <option value="{{ $tpl['value'] }}" @selected($selectedTemplate === $tpl['value'])>{{ $tpl['label'] }}</option>
            @endforeach
        </x-ui.select>
    @endunless

    <x-ui.input
        id="new-provider-name"
        wire:model="providerName"
        label="{{ __('Name') }}"
        required
        placeholder="{{ __('e.g. openai') }}"
        :disabled="$isEditingProvider"
        :error="$errors->first('providerName')"
    />

    <x-ui.input
        id="new-provider-display-name"
        wire:model="providerDisplayName"
        label="{{ __('Display Name') }}"
        placeholder="{{ __('e.g. OpenAI') }}"
        :error="$errors->first('providerDisplayName')"
    />

    <x-ui.input
        id="new-provider-base-url"
        wire:model="providerBaseUrl"
        label="{{ __('Base URL') }}"
        required
        placeholder="{{ __('e.g. https://api.openai.com/v1') }}"
        :error="$errors->first('providerBaseUrl')"
    />

    <x-ui.secret-input
        id="new-provider-api-key"
        wire:model="providerApiKey"
        label="{{ __('API Key') }}"
        :required="!$isEditingProvider"
        :placeholder="$isEditingProvider ? __('Leave blank to keep current key') : ''"
        :error="$errors->first('providerApiKey')"
        :has-value="$isEditingProvider"
        :saved-label="__('Current key:')"
        :saved-value-preview="$providerApiKeyPreview"
        :empty-saved-value-label="__('not set')"
    />

    <x-ui.checkbox id="provider-is-active" wire:model="providerIsActive" label="{{ __('Active') }}" />

    <div class="flex justify-end gap-2 pt-2">
        <x-ui.button variant="ghost" wire:click="$set('showProviderForm', false)">{{ __('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" variant="primary">{{ $isEditingProvider ? __('Update') : __('Create') }}</x-ui.button>
    </div>
</form>
