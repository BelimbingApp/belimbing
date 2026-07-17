<div>
    <x-ui.card>
        <div class="flex items-start gap-3">
            <x-icon name="heroicon-o-exclamation-triangle" class="mt-0.5 h-5 w-5 shrink-0 text-status-warning" />
            <div>
                <p class="text-sm font-medium text-ink">{{ __("This widget couldn't load") }}</p>
                <p class="mt-1 text-xs text-muted">{{ __('The error has been reported. The rest of the dashboard is unaffected.') }}</p>
            </div>
        </div>
    </x-ui.card>
</div>
