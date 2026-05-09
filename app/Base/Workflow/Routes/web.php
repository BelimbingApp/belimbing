<?php
use App\Base\Workflow\Livewire\Workflows\Index as WorkflowsIndex;
use App\Base\Workflow\Livewire\Workflows\Show as WorkflowsShow;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('admin/workflows', WorkflowsIndex::class)
        ->middleware('authz:admin.workflow.manage')
        ->name('admin.workflows.index');

    Route::get('admin/workflows/{workflow}', WorkflowsShow::class)
        ->middleware('authz:admin.workflow.manage')
        ->name('admin.workflows.show');
});
