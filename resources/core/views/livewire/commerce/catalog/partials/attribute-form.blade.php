<form wire:submit="createAttribute" class="space-y-6 p-6">
    <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Attribute') }}</h2>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-ui.select id="catalog-attribute-category" wire:model="attributeCategoryId" label="{{ __('Category') }}" :error="$errors->first('attributeCategoryId')">
            <option value="">{{ __('Any category') }}</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}">{{ $category->path_label }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.select id="catalog-attribute-template" wire:model="attributeProductTemplateId" label="{{ __('Template') }}" :error="$errors->first('attributeProductTemplateId')">
            <option value="">{{ __('Any template') }}</option>
            @foreach ($templates as $template)
                <option value="{{ $template->id }}">{{ $template->name }}</option>
            @endforeach
        </x-ui.select>

        <x-ui.input id="catalog-attribute-name" wire:model.live.debounce.300ms="attributeName" label="{{ __('Name') }}" required :error="$errors->first('attributeName')" />
        <x-ui.input id="catalog-attribute-code" wire:model="attributeCode" label="{{ __('Code') }}" required :error="$errors->first('attributeCode')" />

        <x-ui.select id="catalog-attribute-type" wire:model="attributeType" label="{{ __('Type') }}" :error="$errors->first('attributeType')">
            @foreach ($attributeTypes as $type)
                <option value="{{ $type }}">{{ __(Illuminate\Support\Str::headline($type)) }}</option>
            @endforeach
        </x-ui.select>

        <div class="flex items-end">
            <x-ui.checkbox id="catalog-attribute-required" wire:model="attributeRequired" label="{{ __('Required') }}" />
        </div>
    </div>

    <x-ui.textarea id="catalog-attribute-options" wire:model="attributeOptions" label="{{ __('Options') }}" rows="3" :error="$errors->first('attributeOptions')" />

    <div class="flex items-center gap-4">
        <x-ui.button type="submit" variant="primary">{{ __('Create') }}</x-ui.button>
        <x-ui.button type="button" variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>
