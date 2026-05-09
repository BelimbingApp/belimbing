<x-layouts.app :title="__('Dashboard')">
    <div class="space-y-6">
        <x-ui.page-header title="{{ __('Dashboard') }}" />

        <!-- Dashboard Widgets Grid -->
        <div class="grid gap-6 md:grid-cols-3">
            <div class="bg-surface-card border border-border-default shadow-sm rounded-xl">
                <div class="p-6">
                    <div class="relative aspect-video overflow-hidden rounded-lg">
                        <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>
            </div>
            <div class="bg-surface-card border border-border-default shadow-sm rounded-xl">
                <div class="p-6">
                    <div class="relative aspect-video overflow-hidden rounded-lg">
                        <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>
            </div>
            <div class="bg-surface-card border border-border-default shadow-sm rounded-xl">
                <div class="p-6">
                    <div class="relative aspect-video overflow-hidden rounded-lg">
                        <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content Card -->
        <div class="bg-surface-card border border-border-default shadow-sm rounded-xl">
            <div class="p-6">
                <div class="relative min-h-[400px] overflow-hidden rounded-lg">
                    <x-placeholder-pattern class="absolute inset-0 size-full stroke-gray-900/20 dark:stroke-neutral-100/20" />
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
