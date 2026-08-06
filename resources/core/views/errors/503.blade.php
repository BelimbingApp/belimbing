@extends('errors.layout')

@php
    // An in-app software update stamps the maintenance payload with its run id
    // (DeploymentMaintenanceGuard::enter). When present, say what is actually
    // happening instead of generic "planned work". If the payload is stale and
    // no update lease remains, fall back to standard maintenance copy.
    $blbUpdateRunId = rescue(
        static fn () => app()->isDownForMaintenance()
            ? (app()->maintenanceMode()->data()[\App\Base\Software\Services\DeploymentMaintenanceGuard::MAINTENANCE_DATA_RUN_ID] ?? null)
            : null,
        rescue: null,
        report: false,
    );

    $blbUpdating = false;

    if (is_string($blbUpdateRunId) && $blbUpdateRunId !== '') {
        $blbUpdating = rescue(
            static fn () => app(\App\Base\Software\Services\DeploymentMaintenanceGuard::class)->leaseExists($blbUpdateRunId),
            rescue: false,
            report: false,
        );
    }

    // A stamped run id with no lease left means an update stranded the site: the run
    // is over but its maintenance hold is not. The Updates console is excepted from
    // maintenance and carries the "Bring back online" action, so offer that instead
    // of leaving a shell as the only way out. (This page renders before the session
    // starts, so it cannot tell who is looking — the console does its own auth.)
    $blbRecoveryUrl = null;

    if (is_string($blbUpdateRunId) && $blbUpdateRunId !== '' && ! $blbUpdating) {
        $blbRecoveryUrl = rescue(
            static fn () => route('admin.system.software.updates.index'),
            rescue: null,
            report: false,
        );
    }
@endphp

@if ($blbRecoveryUrl !== null)
    @section('secondary-href', $blbRecoveryUrl)
    @section('secondary-label', __('Administrator: bring the site back online'))
@endif

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
