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

    <div class="space-y-1">
        <label for="new-provider-api-key" class="block text-[11px] uppercase tracking-wider font-semibold text-muted">
            {{ __('API Key') }}
            @if(!$isEditingProvider)
                <span class="text-status-danger">*</span>
            @endif
            @if($isEditingProvider)
                <span class="ml-2 normal-case tracking-normal font-normal text-xs text-muted">
                    {{ __('Current key:') }}
                    <span class="{{ filled($providerApiKeyPreview) ? 'font-mono' : '' }}">{{ filled($providerApiKeyPreview) ? $providerApiKeyPreview : __('not set') }}</span>
                </span>
            @endif
        </label>

        <x-ui.input
            id="new-provider-api-key"
            wire:model="providerApiKey"
            type="password"
            :required="!$isEditingProvider"
            :placeholder="$isEditingProvider ? __('Leave blank to keep current key') : ''"
            :error="$errors->first('providerApiKey')"
        />
    </div>

    <x-ui.checkbox id="provider-is-active" wire:model="providerIsActive" label="{{ __('Active') }}" />

    <div class="flex justify-end gap-2 pt-2">
        <x-ui.button variant="ghost" wire:click="$set('showProviderForm', false)">{{ __('Cancel') }}</x-ui.button>
        <x-ui.button type="submit" variant="primary">{{ $isEditingProvider ? __('Update') : __('Create') }}</x-ui.button>
    </div>
</form>
