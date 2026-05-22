<form wire:submit="createTemplate" class="space-y-6 p-6">
    <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Template') }}</h2>

    <div class="space-y-4">
        <x-ui.select id="catalog-template-category" wire:model="templateCategoryId" label="{{ __('Category') }}" :error="$errors->first('templateCategoryId')">
            <option value="">{{ __('Any category') }}</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}">{{ $category->path_label }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.input id="catalog-template-name" wire:model.live.debounce.300ms="templateName" label="{{ __('Name') }}" required :error="$errors->first('templateName')" />
        <x-ui.input id="catalog-template-code" wire:model="templateCode" label="{{ __('Code') }}" required :error="$errors->first('templateCode')" />
        <x-ui.textarea id="catalog-template-description" wire:model="templateDescription" label="{{ __('Description') }}" rows="3" :error="$errors->first('templateDescription')" />
    </div>

    <div class="flex items-center gap-4">
        <x-ui.button type="submit" variant="primary">{{ __('Create') }}</x-ui.button>
        <x-ui.button type="button" variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>
