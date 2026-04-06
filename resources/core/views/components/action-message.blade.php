@props([
    'on',
])

<div
    x-data="{ shown: false, timeout: null, msg: '' }"
    x-init="@this.on('{{ $on }}', (e) => { clearTimeout(timeout); msg = e?.message || ''; shown = true; timeout = setTimeout(() => { shown = false }, 1800); })"
    x-show.transition.out.opacity.duration.1500ms="shown"
    x-transition:leave.opacity.duration.1500ms
    style="display: none"
    {{ $attributes->merge(['class' => 'text-sm']) }}
>
    <template x-if="msg"><span x-text="msg"></span></template>
    <template x-if="!msg">{{ $slot->isEmpty() ? __('Saved.') : $slot }}</template>
</div>
