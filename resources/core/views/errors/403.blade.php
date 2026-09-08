@php
    $forbiddenTitle = __('Access restricted');
    $forbiddenMessage = __('You do not have permission to access this page. Choose another page from the navigation, or contact your administrator if you need access.');
@endphp

{{-- Never render the exception message: it may identify protected resources. --}}
@if (auth()->check() && app(\App\Base\Tenancy\Contracts\TenantContext::class)->hasTenant())
    <x-layouts.app :title="$forbiddenTitle">
        <x-ui.page-header
            :title="$forbiddenTitle"
            :subtitle="$forbiddenMessage"
            :pinnable="false"
        />
    </x-layouts.app>
@else
    @include('errors.403-guest', ['forbiddenTitle' => $forbiddenTitle])
@endif
