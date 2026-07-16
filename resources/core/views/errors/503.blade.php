@extends('errors.layout')

@php
    // An in-app software update stamps the maintenance payload with its run id
    // (DeploymentMaintenanceGuard::enter). When present, say what is actually
    // happening instead of generic "planned work".
    $blbUpdateRunId = rescue(
        static fn () => app()->isDownForMaintenance()
            ? (app()->maintenanceMode()->data()[\App\Base\Software\Services\DeploymentMaintenanceGuard::MAINTENANCE_DATA_RUN_ID] ?? null)
            : null,
        rescue: null,
        report: false,
    );
    $blbUpdating = is_string($blbUpdateRunId) && $blbUpdateRunId !== '';
@endphp

@section('head')
    {{-- The copy promises the site will be back shortly — keep that promise
         for the user: retry automatically instead of leaving them to refresh. --}}
    <meta http-equiv="refresh" content="15">
@endsection

@section('code', '503')
@section('title', $blbUpdating ? __('Installing an update') : __('Down for maintenance'))
@section('message', $blbUpdating
    ? __('Belimbing is installing a software update. Your data is safe — this page will bring you back the moment it finishes.')
    : __('We are doing planned work on the system. Your data is safe — this page will retry on its own until we are back.'))
