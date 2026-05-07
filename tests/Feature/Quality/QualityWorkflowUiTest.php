<?php

use App\Base\Authz\DTO\Actor;
use App\Modules\Operation\Quality\Contracts\NumberingService;
use App\Modules\Operation\Quality\Database\Seeders\NcrWorkflowSeeder;
use App\Modules\Operation\Quality\Database\Seeders\ScarWorkflowSeeder;
use App\Modules\Operation\Quality\Livewire\Ncr\Show as NcrShow;
use App\Modules\Operation\Quality\Livewire\Scar\Create as ScarCreate;
use App\Modules\Operation\Quality\Livewire\Scar\Show as ScarShow;
use App\Modules\Operation\Quality\Models\Capa;
use App\Modules\Operation\Quality\Models\Ncr;
use App\Modules\Operation\Quality\Models\QualityEvent;
use App\Modules\Operation\Quality\Models\Scar;
use App\Modules\Operation\Quality\Services\NcrService;
use App\Modules\Operation\Quality\Services\ScarService;
use Livewire\Livewire;

beforeEach(function (): void {
    setupAuthzRoles();
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    $this->artisan('migrate', [
        '--path' => base_path('app/Modules/Operation/Quality/Database/Migrations'),
        '--realpath' => true,
    ]);
    require_once base_path('app/Modules/Operation/Quality/Routes/web.php');
    (new NcrWorkflowSeeder)->run();
    (new ScarWorkflowSeeder)->run();
});

test('scar create redirects to the ncr list when no ncr is selected', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(ScarCreate::class)
        ->assertRedirect(route('quality.ncr.index'));

    expect(session('error'))->toBe(__('Select an NCR before creating a SCAR.'));
});

test('ncr show starts investigation without skipping straight to under review', function (): void {
    $actor = createAdminUser();
    $this->actingAs($actor);

    $ncr = Ncr::factory()->create([
        'company_id' => $actor->company_id,
        'status' => 'assigned',
    ]);

    Capa::query()->create([
        'ncr_id' => $ncr->id,
        'workflow_status' => 'assigned',
    ]);

    Livewire::test(NcrShow::class, ['ncr' => $ncr])
        ->call('transitionTo', 'in_progress');

    $ncr->refresh();
    $ncr->load('capa');

    expect($ncr->status)->toBe('in_progress')
        ->and($ncr->capa)->not()->toBeNull()
        ->and($ncr->capa->workflow_status)->toBe('in_progress');
});

test('scar show begins review without requiring an acceptance decision', function (): void {
    $actor = createAdminUser();
    $this->actingAs($actor);

    $scar = Scar::factory()->create([
        'status' => 'response_submitted',
    ]);

    Livewire::test(ScarShow::class, ['scar' => $scar])
        ->call('transitionTo', 'under_review');

    expect($scar->fresh()->status)->toBe('under_review');
});

test('scar show verify and close transition records verification fields', function (): void {
    $actor = createAdminUser();
    $this->actingAs($actor);

    $scar = Scar::factory()->create([
        'status' => 'verification_pending',
        'verified_by_user_id' => null,
        'verified_at' => null,
        'closed_by_user_id' => null,
        'closed_at' => null,
    ]);

    Livewire::test(ScarShow::class, ['scar' => $scar])
        ->set('transitionComment', 'Verified effective')
        ->call('transitionTo', 'closed');

    $scar->refresh();

    expect($scar->status)->toBe('closed')
        ->and($scar->verified_by_user_id)->toBe($actor->id)
        ->and($scar->verified_at)->not()->toBeNull()
        ->and($scar->closed_by_user_id)->toBe($actor->id)
        ->and($scar->closed_at)->not()->toBeNull();
});

test('quality events accept actor type through mass assignment', function (): void {
    $ncr = Ncr::factory()->create();

    $event = QualityEvent::query()->create([
        'ncr_id' => $ncr->id,
        'event_type' => 'evidence_ingested',
        'actor_type' => 'system',
        'actor_id' => null,
        'occurred_at' => now(),
    ]);

    expect($event->actor_type)->toBe('system');
});

test('ncr service retries when a generated number collides', function (): void {
    $actor = createAdminUser();
    $companyId = $actor->company_id;

    Ncr::factory()->create([
        'company_id' => $companyId,
        'ncr_no' => 'NCR-000001',
    ]);

    app()->instance(NumberingService::class, new class implements NumberingService
    {
        /** @var array<int, string> */
        private array $ncrNumbers = ['NCR-000001', 'NCR-000002'];

        public function nextNcrNumber(string $ncrKind): string
        {
            return array_shift($this->ncrNumbers) ?? 'NCR-999999';
        }

        public function nextScarNumber(): string
        {
            return 'SCAR-000001';
        }
    });

    $ncr = app(NcrService::class)->open(Actor::forUser($actor), [
        'company_id' => $companyId,
        'ncr_kind' => 'internal',
        'title' => 'Duplicate number retry',
        'reported_by_name' => 'Quality Engineer',
    ]);

    expect($ncr->ncr_no)->toBe('NCR-000002');
});

test('scar service retries when a generated number collides', function (): void {
    $actor = createAdminUser();

    $ncr = Ncr::factory()->create([
        'company_id' => $actor->company_id,
    ]);

    Scar::factory()->create([
        'ncr_id' => $ncr->id,
        'scar_no' => 'SCAR-000001',
    ]);

    app()->instance(NumberingService::class, new class implements NumberingService
    {
        /** @var array<int, string> */
        private array $scarNumbers = ['SCAR-000001', 'SCAR-000002'];

        public function nextNcrNumber(string $ncrKind): string
        {
            return 'NCR-000001';
        }

        public function nextScarNumber(): string
        {
            return array_shift($this->scarNumbers) ?? 'SCAR-999999';
        }
    });

    $scar = app(ScarService::class)->create(Actor::forUser($actor), $ncr, [
        'supplier_name' => 'Acme Supplier',
    ]);

    expect($scar->scar_no)->toBe('SCAR-000002');
});
