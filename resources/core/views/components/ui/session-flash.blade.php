{{--
    Renders flashed session feedback as status alerts. Reads the `success` and
    `error` flash keys set by Livewire actions (`session()->flash(...)`) and
    renders the matching `x-ui.alert` variant. Forwarded attributes (e.g.
    `class="mb-4"`) apply to each rendered alert.
--}}
@foreach (['success' => 'success', 'error' => 'error'] as $sessionKey => $variant)
    @if (session($sessionKey))
        <x-ui.alert :variant="$variant" {{ $attributes }}>{{ session($sessionKey) }}</x-ui.alert>
    @endif
@endforeach
