@props([
    'show' => false,
    'labelledby' => null,
    'label' => null,
    'confirmDiscard' => false,
])

<div
    x-data="{
        show: @entangle($attributes->wire('model')), dirty: false, previousFocus: null,
        init() {
            this.$watch('show', value => {
                if (value) {
                    this.dirty = false;
                    this.previousFocus = document.activeElement;
                    this.$nextTick(() => this.$el.querySelector('input:not([type=hidden]), select, textarea, button, [tabindex]')?.focus());
                } else { this.previousFocus?.focus(); }
            });
        },
        requestClose() {
            if (@js($confirmDiscard) &amp;&amp; this.dirty &amp;&amp; !window.confirm(@js(__('Discard unsaved changes?')))) return;
            this.dirty = false; this.show = false;
        },
        trap(event) {
            const controls = [...this.$el.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')].filter(el => !el.disabled &amp;&amp; el.tabIndex >= 0 &amp;&amp; el.getClientRects().length);
            if (!controls.length) return;
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey &amp;&amp; document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey &amp;&amp; document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    }"
    x-show="show"
    x-cloak
    @keydown.escape.window="if(show) { $event.preventDefault(); requestClose(); }"
    @keydown.tab="trap($event)"
    @input="dirty = true"
    @change="dirty = true"
    class="fixed inset-0 z-50 overflow-y-auto"
    @if($labelledby || $label)
        role="dialog"
        aria-modal="true"
    @endif
    @if($labelledby) aria-labelledby="{{ $labelledby }}" @endif
    @if($label) aria-label="{{ $label }}" @endif
    style="display: none;"
>
    <!-- Backdrop -->
    <div
        x-show="show"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        @click="requestClose()"
        class="fixed inset-0 bg-black/50"
    ></div>

    <!-- Modal -->
    <div class="flex min-h-full items-center justify-center p-4">
        <div
            x-show="show"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            @click.stop
            {{ $attributes->merge(['class' => 'relative bg-surface-card border border-border-default rounded-2xl shadow-xl w-full']) }}
        >
            {{ $slot }}
        </div>
    </div>
</div>
