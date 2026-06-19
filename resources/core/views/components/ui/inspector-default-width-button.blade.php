<button
    type="button"
    @click="resetToDefaultWidth()"
    class="hidden rounded p-1 text-muted transition-colors hover:bg-surface-subtle hover:text-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 sm:inline-flex"
    aria-label="{{ __('Reset inspector width') }}"
    title="{{ __('Reset inspector width') }}"
>
    <x-icon name="heroicon-o-arrows-pointing-in" class="size-5" />
</button>
