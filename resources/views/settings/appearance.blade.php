<x-layouts.app :title="__('Appearance Settings')">
    <section class="w-full">
        @include('partials.settings-heading')

        <x-settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
            <div x-data="{ theme: localStorage.getItem('theme') ?? 'system' }" class="flex gap-2">
                <x-ui.radio id="theme-light" name="theme" value="light" x-model="theme" @change="localStorage.setItem('theme', theme)" label="{{ __('Light') }}" />
                <x-ui.radio id="theme-dark" name="theme" value="dark" x-model="theme" @change="localStorage.setItem('theme', theme)" label="{{ __('Dark') }}" />
                <x-ui.radio id="theme-system" name="theme" value="system" x-model="theme" @change="localStorage.setItem('theme', theme)" label="{{ __('System') }}" />
            </div>
        </x-settings.layout>
    </section>
</x-layouts.app>
