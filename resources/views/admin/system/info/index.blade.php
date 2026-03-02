<x-layouts.app :title="__('System Info')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('System Info')" :subtitle="__('Server and application configuration')" />

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <x-ui.card>
                <h3 class="text-sm font-medium text-ink mb-3">{{ __('Laravel') }}</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Version') }}</dt><dd class="text-ink font-medium tabular-nums">{{ $laravel['version'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Environment') }}</dt><dd><x-ui.badge :variant="$laravel['environment'] === 'production' ? 'danger' : 'default'">{{ $laravel['environment'] }}</x-ui.badge></dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Debug Mode') }}</dt><dd><x-ui.badge :variant="$laravel['debug'] ? 'warning' : 'success'">{{ $laravel['debug'] ? __('Enabled') : __('Disabled') }}</x-ui.badge></dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('URL') }}</dt><dd class="text-ink">{{ $laravel['url'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Timezone') }}</dt><dd class="text-ink">{{ $laravel['timezone'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Locale') }}</dt><dd class="text-ink">{{ $laravel['locale'] }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-sm font-medium text-ink mb-3">{{ __('PHP') }}</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Version') }}</dt><dd class="text-ink font-medium tabular-nums">{{ $php['version'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('SAPI') }}</dt><dd class="text-ink">{{ $php['sapi'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Memory Limit') }}</dt><dd class="text-ink tabular-nums">{{ $php['memory_limit'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Max Execution Time') }}</dt><dd class="text-ink tabular-nums">{{ $php['max_execution_time'] }}s</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Upload Max Filesize') }}</dt><dd class="text-ink tabular-nums">{{ $php['upload_max_filesize'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Post Max Size') }}</dt><dd class="text-ink tabular-nums">{{ $php['post_max_size'] }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-sm font-medium text-ink mb-3">{{ __('Database') }}</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Connection') }}</dt><dd class="text-ink font-medium">{{ $database['connection'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Driver') }}</dt><dd class="text-ink">{{ $database['driver'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Database') }}</dt><dd class="text-ink">{{ $database['database'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Host') }}</dt><dd class="text-ink">{{ $database['host'] }}</dd></div>
                </dl>
            </x-ui.card>

            <x-ui.card>
                <h3 class="text-sm font-medium text-ink mb-3">{{ __('Server') }}</h3>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">{{ __('OS') }}</dt><dd class="text-ink">{{ $server['os'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Hostname') }}</dt><dd class="text-ink">{{ $server['hostname'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Server Software') }}</dt><dd class="text-ink">{{ $server['server_software'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Disk Free') }}</dt><dd class="text-ink tabular-nums">{{ $server['disk_free'] }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted">{{ __('Disk Total') }}</dt><dd class="text-ink tabular-nums">{{ $server['disk_total'] }}</dd></div>
                </dl>
            </x-ui.card>
        </div>

        <x-ui.card>
            <h3 class="text-sm font-medium text-ink mb-3">{{ __('PHP Extensions') }} <span class="text-muted">({{ count($php['extensions']) }})</span></h3>
            <div class="flex flex-wrap gap-1.5">
                @foreach (collect($php['extensions'])->sort() as $ext)
                    <x-ui.badge>{{ $ext }}</x-ui.badge>
                @endforeach
            </div>
        </x-ui.card>
    </div>
</x-layouts.app>
