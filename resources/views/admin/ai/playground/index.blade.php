<x-layouts.app :title="__('Digital Worker Playground')">
    <div class="space-y-section-gap">
        <x-ui.page-header :title="__('Digital Worker Playground')" />

        <x-ui.card>
            <form method="GET" action="{{ route('admin.ai.playground') }}" class="flex items-end gap-3">
                <div class="w-80">
                    <x-ui.select name="employee_id" label="{{ __('Digital Worker') }}">
                        @foreach($digitalWorkers as $dw)
                            <option value="{{ $dw->id }}" @selected($selectedEmployeeId === $dw->id)>{{ $dw->displayName() }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.button type="submit">{{ __('Load') }}</x-ui.button>
                <x-ui.button as="a" variant="ghost" href="{{ route('admin.ai.providers.index') }}">{{ __('Manage Providers') }}</x-ui.button>
            </form>
        </x-ui.card>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-3">
            <x-ui.card class="lg:col-span-1">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Sessions') }}</span>
                    @if($selectedEmployeeId > 0)
                        <form method="POST" action="{{ route('admin.ai.playground.sessions') }}">
                            @csrf
                            <input type="hidden" name="employee_id" value="{{ $selectedEmployeeId }}">
                            <x-ui.button type="submit" size="sm" variant="ghost"><x-icon name="heroicon-o-plus" class="w-4 h-4" /></x-ui.button>
                        </form>
                    @endif
                </div>
                <div class="space-y-1">
                    @forelse($sessions as $session)
                        <a href="{{ route('admin.ai.playground', ['employee_id' => $selectedEmployeeId, 'session_id' => $session->id]) }}" class="block px-2 py-1.5 rounded-lg text-sm {{ $selectedSessionId === $session->id ? 'bg-surface-subtle text-ink' : 'text-muted hover:bg-surface-subtle/50 hover:text-ink' }}">
                            <div class="truncate font-medium">{{ $session->title ?? __('Untitled') }}</div>
                            <div class="text-xs text-muted tabular-nums">{{ $session->lastActivityAt->format('M j, H:i') }}</div>
                        </a>
                    @empty
                        <p class="text-sm text-muted">{{ __('No sessions yet.') }}</p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card class="lg:col-span-2">
                <div class="space-y-3 max-h-[60vh] overflow-y-auto">
                    @forelse($messages as $message)
                        <div class="flex {{ $message->role === 'user' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[75%] rounded-2xl px-3 py-2 text-sm {{ $message->role === 'user' ? 'bg-accent text-accent-on' : 'bg-surface-subtle text-ink' }}">
                                <div class="whitespace-pre-wrap break-words">{{ $message->content }}</div>
                                <div class="text-[10px] mt-1 {{ $message->role === 'user' ? 'text-accent-on/70' : 'text-muted' }} tabular-nums">{{ $message->timestamp->format('H:i:s') }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-muted">{{ __('Create a session and send a message to begin.') }}</p>
                    @endforelse
                </div>

                @if($selectedEmployeeId > 0 && $selectedSessionId !== '')
                    <form method="POST" action="{{ route('admin.ai.playground.messages') }}" class="flex gap-2 items-end mt-3 border-t border-border-default pt-3">
                        @csrf
                        <input type="hidden" name="employee_id" value="{{ $selectedEmployeeId }}">
                        <input type="hidden" name="session_id" value="{{ $selectedSessionId }}">
                        <div class="flex-1 min-w-0"><x-ui.input name="message" placeholder="{{ __('Type a message...') }}" autocomplete="off" /></div>
                        <x-ui.button type="submit" variant="primary"><x-icon name="heroicon-o-paper-airplane" class="w-4 h-4" /></x-ui.button>
                    </form>
                @endif
            </x-ui.card>

            <x-ui.card class="lg:col-span-1">
                <span class="text-[11px] uppercase tracking-wider font-semibold text-muted">{{ __('Debug') }}</span>
                @if($lastRunMeta)
                    <dl class="mt-2 space-y-1.5 text-xs">
                        <div><dt class="text-muted">{{ __('Run ID') }}</dt><dd class="text-ink font-mono tabular-nums">{{ $lastRunMeta['run_id'] ?? '-' }}</dd></div>
                        <div><dt class="text-muted">{{ __('Model') }}</dt><dd class="text-ink">{{ $lastRunMeta['model'] ?? '-' }}</dd></div>
                        <div><dt class="text-muted">{{ __('Latency') }}</dt><dd class="text-ink tabular-nums">{{ isset($lastRunMeta['latency_ms']) ? $lastRunMeta['latency_ms'].'ms' : '-' }}</dd></div>
                        @if(isset($lastRunMeta['tokens']))<div><dt class="text-muted">{{ __('Tokens') }}</dt><dd class="text-ink tabular-nums">{{ $lastRunMeta['tokens']['prompt'] ?? '?' }} → {{ $lastRunMeta['tokens']['completion'] ?? '?' }}</dd></div>@endif
                        @if(isset($lastRunMeta['error']))<div><dt class="text-muted">{{ __('Error') }}</dt><dd class="text-status-danger">{{ $lastRunMeta['error'] }}</dd></div>@endif
                    </dl>
                @else
                    <p class="mt-2 text-xs text-muted">{{ __('Send a message to see runtime metadata.') }}</p>
                @endif
            </x-ui.card>
        </div>
    </div>
</x-layouts.app>
