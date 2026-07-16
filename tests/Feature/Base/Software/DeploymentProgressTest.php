<?php

use App\Base\Software\Services\DeploymentRunHistory;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

it('returns the durable run record as a live progress feed', function (): void {
    $runId = (string) Str::uuid();
    $history = app(DeploymentRunHistory::class);
    $history->beginDeploymentRun($runId, [], 'Software update scheduled in a detached process.');
    $history->appendDeploymentLine($runId, 'Pulling Belimbing…');
    $history->appendDeploymentLine($runId, 'FAILED: dependency refresh did not complete; deployment halted before migrations and reload.');

    $this->actingAs(createAdminUser());

    $response = $this->getJson(route('admin.system.software.updates.progress'));

    $response->assertOk()
        ->assertJsonPath('status', 'pending')
        ->assertJsonPath('lines.1.text', 'Pulling Belimbing…')
        ->assertJsonPath('lines.2.class', 'text-status-danger');
});

it('reports idle when no update has ever run', function (): void {
    $this->actingAs(createAdminUser());

    $this->getJson(route('admin.system.software.updates.progress'))
        ->assertOk()
        ->assertJsonPath('status', 'idle')
        ->assertJsonPath('lines', []);
});

it('stays reachable while the site is down for maintenance', function (): void {
    // The whole point of this route: an update holds the site in maintenance
    // mode while the operator watches its progress. If this 503s, the run box
    // goes dark exactly when it matters.
    $this->actingAs(createAdminUser());

    Artisan::call('down', ['--retry' => 5]);

    try {
        $this->getJson(route('admin.system.software.updates.progress'))->assertOk();
    } finally {
        Artisan::call('up');
    }
});

it('denies users who cannot manage updates', function (): void {
    $this->actingAs(User::factory()->create());

    $this->getJson(route('admin.system.software.updates.progress'))->assertForbidden();
});
