@if(session('impersonation.original_user_id'))
    <x-ui.banner
        variant="warning"
        icon="heroicon-o-eye"
        :title="__('Viewing as :name', ['name' => auth()->user()->name])"
    >
        <x-slot name="action">
            <form method="POST" action="{{ route('admin.impersonate.stop') }}">
                @csrf
                <button type="submit" class="text-xs font-medium text-status-warning hover:underline">
                    {{ __('Stop Impersonation') }}
                </button>
            </form>
        </x-slot>
    </x-ui.banner>
@endif
