{{--
    Contributions a bundle delivers to host module seams (Software Inventory, Phase 4).
    Expects: $contributions — list<App\Base\Software\Inventory\ContributionSummary>.
    Read-only: the human label leads; the seam id is shown as secondary technical detail.
--}}
@if (count($contributions ?? []) > 0)
    <div class="mt-3 space-y-1">
        <div class="text-xs font-semibold uppercase tracking-wide text-muted">{{ __('Contributions') }}</div>
        @foreach ($contributions as $contribution)
            <div class="rounded-lg border border-border-default px-3 py-2 text-xs">
                <div class="flex items-center justify-between gap-2">
                    <span class="font-medium text-ink">{{ $contribution->label }}</span>
                    <div class="flex items-center gap-1">
                        <x-ui.badge variant="info">{{ $contribution->kind }}</x-ui.badge>
                        @if ($contribution->status !== 'active')
                            <x-ui.badge variant="warning">{{ $contribution->status }}</x-ui.badge>
                        @endif
                    </div>
                </div>
                <div class="mt-1 font-mono text-muted">{{ $contribution->hostModule }} · {{ $contribution->seam }}</div>
                @if (! empty($contribution->metadata))
                    <div class="mt-1 text-muted">
                        @foreach ($contribution->metadata as $key => $value)
                            <span class="mr-2 inline-block">{{ $key }}: <span class="text-ink">{{ $value }}</span></span>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
@endif
