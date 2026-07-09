<?php

use App\Base\Schedule\Livewire\ScheduledTasks\Index;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    setupAuthzRoles();
});

test('scheduled tasks page lists withSchedule and provider-booted commands', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Index::class)
        ->assertOk()
        ->assertSee('commerce:marketplace:ebay:pull --orders', false)
        ->assertSee('commerce:marketplace:ebay:metadata-refresh', false)
        ->assertSee('blb:ai:runs:reap-orphans', false)
        ->assertSee('blb:ai:turns:sweep-stale', false)
        ->assertSee('blb:ai:pricing:refresh', false)
        ->assertSee('Last run')
        ->assertSee('Next run')
        ->assertSee('Status')
        ->assertSee('History')
        ->assertSee('Never')
        ->assertDontSee('Timezone');
});

test('unauthenticated request is redirected from scheduled tasks', function (): void {
    $this->get(route('admin.system.scheduled-tasks.index'))
        ->assertRedirect();
});

test('authenticated user without list capability is forbidden', function (): void {
    $user = User::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);

    $this->actingAs($user)
        ->get(route('admin.system.scheduled-tasks.index'))
        ->assertForbidden();
});
