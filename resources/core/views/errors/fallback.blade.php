{{--
    Source of the static fallback pages under public/errors/. Rendered by
    `blb:error-pages:publish` (see App\Base\Foundation\Services\ErrorPages),
    never served by Laravel itself: Caddy shows it when FrankenPHP cannot
    answer, and Cloudflare shows the variant carrying its diagnostics box
    when the whole server is unreachable. Nothing here may depend on the
    request, the session, or the database, and every href must be relative.

    $cloudflareBox — true renders Cloudflare's required 5xx token, which the
    CDN replaces with its own diagnostics (Ray ID, error text) at serve time.
--}}
@extends('errors.layout')

@section('head')
    {{-- The copy promises a retry — keep it: reload every 20 seconds until
         the server answers again. A server switch-over takes minutes, not hours. --}}
    <meta http-equiv="refresh" content="20">
@endsection

@section('code', __('Server unavailable'))
@section('title', __("We'll be right back"))
@section('message', __('The server did not answer. Nothing you did caused this. This page retries on its own, or you can try again now.'))

{{-- An empty href is the current address: retry exactly what the visitor asked for. --}}
@section('primary-href', '')
@section('primary-label', __('Try again'))

@if ($cloudflareBox)
    @section('diagnostic', \App\Base\Foundation\Services\ErrorPages::CLOUDFLARE_TOKEN)
@endif
