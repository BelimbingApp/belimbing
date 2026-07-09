<div>
    <x-slot name="title">{{ __('Scheduled Tasks') }}</x-slot>

    <div
        class="space-y-section-gap"
        @if ($hasRunning) wire:poll.3s @endif
    >
        <x-ui.page-header
            :title="__('Scheduled Tasks')"
            :subtitle="__(':count registered scheduled commands', ['count' => $totalCount])"
        >
            <x-slot name="help">
                <div class="space-y-2">
                    <p>{{ __('Tasks shows Laravel scheduler registrations plus the last observed status. History lists every recorded attempt. Settings controls how long history is kept.') }}</p>
                    <p>{{ __('Cron expressions are evaluated in UTC. Last run and Next run use the same datetime display as the rest of the app (company, UTC, or local).') }}</p>
                    <p>{{ __('The play control queues that registered command now. It honors scheduler filters (when/skip) and forces a foreground finish so status is recorded here; it is not a full schedule:run clone. While a command is Running, this page refreshes automatically. Run now is refused if that command already has a running attempt.') }}</p>
                    <p>{{ __('On Tasks, #N is the last-run row id. On History, #N is the history attempt id.') }}</p>
                    <p>{{ __('Command prefixes identify ownership: blb: = Base/Core, commerce: = Commerce domain, ham: and other prefixes = extensions.') }}</p>
                </div>
            </x-slot>
        </x-ui.page-header>

        <x-ui.tabs
            :tabs="[
                ['id' => 'tasks', 'label' => __('Tasks')],
                ['id' => 'history', 'label' => __('History')],
                ['id' => 'settings', 'label' => __('Settings')],
            ]"
            default="tasks"
            size="sm"
        >
            <x-ui.tab id="tasks">
                @include('livewire.admin.system.scheduled-tasks.partials.tasks')
            </x-ui.tab>

            <x-ui.tab id="history">
                @include('livewire.admin.system.scheduled-tasks.partials.history')
            </x-ui.tab>

            <x-ui.tab id="settings">
                @include('livewire.admin.system.scheduled-tasks.partials.settings')
            </x-ui.tab>
        </x-ui.tabs>
    </div>
</div>
