<x-ui.card>
    <h3 class="mb-4 text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('History retention') }}</h3>

    @if ($canManageRetention)
        <dl class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <x-ui.edit-in-place.text
                :label="__('Keep Days')"
                :value="(string) $keepDays"
                field="schedule.history.keep_days"
                save-method="saveField"
                :help="__('Delete history older than this many days (by finished_at, else started_at). 0 disables age pruning. Applied by blb:schedule:history:prune.')"
            />
            <x-ui.edit-in-place.text
                :label="__('Keep Count')"
                :value="(string) $keepCount"
                field="schedule.history.keep_count"
                save-method="saveField"
                :help="__('After age pruning, keep at most this many newest history rows. 0 disables the count cap. Never deletes last-run rows on Tasks.')"
            />
        </dl>
    @else
        <dl class="grid grid-cols-1 gap-4 md:grid-cols-2 text-sm">
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Keep Days') }}</dt>
                <dd class="mt-1 text-ink tabular-nums">{{ $keepDays }}</dd>
            </div>
            <div>
                <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted">{{ __('Keep Count') }}</dt>
                <dd class="mt-1 text-ink tabular-nums">{{ $keepCount }}</dd>
            </div>
        </dl>
    @endif

    <p class="mt-4 text-xs text-muted">
        {{ __('Prune runs daily at 03:15 UTC via blb:schedule:history:prune. Values are stored in base_settings.') }}
    </p>
</x-ui.card>
