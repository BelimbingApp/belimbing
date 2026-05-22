<form wire:submit="createCategory" class="space-y-6 p-6">
    <h2 class="text-lg font-medium tracking-tight text-ink">{{ __('Add Category') }}</h2>

    <div class="space-y-4">
        <x-ui.select id="catalog-category-parent" wire:model="categoryParentId" label="{{ __('Parent category') }}" :error="$errors->first('categoryParentId')">
            <option value="">{{ __('Top level') }}</option>
            @foreach ($categories as $category)
                <option value="{{ $category->id }}">{{ $category->path_label }}</option>
            @endforeach
        </x-ui.select>
        <x-ui.input id="catalog-category-name" wire:model.live.debounce.300ms="categoryName" label="{{ __('Name') }}" required :error="$errors->first('categoryName')" />
        <x-ui.input id="catalog-category-code" wire:model="categoryCode" label="{{ __('Code') }}" required :error="$errors->first('categoryCode')" />
        <x-ui.textarea id="catalog-category-description" wire:model="categoryDescription" label="{{ __('Description') }}" rows="3" :error="$errors->first('categoryDescription')" />
    </div>

    <div class="flex items-center gap-4">
        <x-ui.button type="submit" variant="primary">{{ __('Create') }}</x-ui.button>
        <x-ui.button type="button" variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</x-ui.button>
    </div>
</form>
