<x-ui.alert variant="info">
    {{ __('This provider requires a dedicated OAuth sign-in flow. Belimbing does not implement a generic OAuth connector for it yet.') }}
</x-ui.alert>

<p class="mt-3 text-sm text-muted">
    {{ __('Use a provider-specific setup page when one exists. Otherwise leave this provider disconnected until Belimbing adds first-class support for its OAuth contract.') }}
</p>
