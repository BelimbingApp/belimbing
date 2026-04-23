<div>
    <x-slot name="title">{{ __('UI Reference') }}</x-slot>

    <x-ui.side-panel-layout
        storage-key="uiReferenceNavWidth"
        :default-width="248"
        :min-width="216"
        :max-width="360"
    >
        <x-slot name="mobilePanel">
            @include('livewire.admin.system.ui-reference.partials.section-nav', ['mode' => 'card'])
        </x-slot>

        <x-slot name="panel">
            @include('livewire.admin.system.ui-reference.partials.section-nav', ['mode' => 'rail'])
        </x-slot>

        <x-ui.page-header
            :title="__('UI Reference')"
            :subtitle="__('Authoritative visual and behavioral reference for BLB UI patterns. Use these pages to compare production screens against the standard and to choose the right primitive before building.')"
            :pinnable="false"
        >
            <x-slot name="help">
                <div class="space-y-2">
                    <p>{{ __('This area is the rendered reference for human review. `DESIGN.md` explains intent, `tokens.css` holds implemented values, and these pages show how the standards look and behave inside the real app shell.') }}</p>
                    <p>{{ __('Interactive sections are intentional. Click, type, dismiss, open, and compare patterns here before asking agents to assemble them on a feature page.') }}</p>
                </div>
            </x-slot>
        </x-ui.page-header>

        <div class="mt-4 min-w-0">
            @switch($currentSection)
                @case(\App\Base\System\Enums\UiReferenceSection::Foundations)
                    @include('livewire.admin.system.ui-reference.partials.foundations')
                    @break

                @case(\App\Base\System\Enums\UiReferenceSection::Inputs)
                    @include('livewire.admin.system.ui-reference.partials.inputs')
                    @break

                @case(\App\Base\System\Enums\UiReferenceSection::Feedback)
                    @include('livewire.admin.system.ui-reference.partials.feedback')
                    @break

                @case(\App\Base\System\Enums\UiReferenceSection::Actions)
                    @include('livewire.admin.system.ui-reference.partials.actions')
                    @break

                @case(\App\Base\System\Enums\UiReferenceSection::Navigation)
                    @include('livewire.admin.system.ui-reference.partials.navigation')
                    @break

                @case(\App\Base\System\Enums\UiReferenceSection::Overlays)
                    @include('livewire.admin.system.ui-reference.partials.overlays')
                    @break

                @case(\App\Base\System\Enums\UiReferenceSection::DataDisplay)
                    @include('livewire.admin.system.ui-reference.partials.data-display')
                    @break

                @case(\App\Base\System\Enums\UiReferenceSection::CompositePatterns)
                    @include('livewire.admin.system.ui-reference.partials.composite-patterns')
                    @break
            @endswitch
        </div>
    </x-ui.side-panel-layout>
</div>
