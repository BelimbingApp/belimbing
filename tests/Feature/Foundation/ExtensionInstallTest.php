<?php

use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Livewire\Modules;
use App\Base\Foundation\Services\ExtensionInstaller;
use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Support\FakeDomainRuntimeReloader;

const EXT_FOLDER = 'zzkiat';
const EXT_REPO = 'https://github.com/zzowner/blb-zzkiat';
const EXT_OWNER = 'zzowner';
const EXT_TOKEN = 'ghp_testtoken1234567890abcdef';

beforeEach(function (): void {
    app()->instance(DomainRuntimeReloader::class, new FakeDomainRuntimeReloader);
    setupAuthzRoles();
    config(['extensions.catalog' => [
        EXT_FOLDER => ['repo' => EXT_REPO, 'description' => 'Test extension.'],
    ]]);
});

afterEach(function (): void {
    File::deleteDirectory(base_path('extensions/'.EXT_FOLDER));
});

it('marks a catalog extension token-ready when a token is stored for its owner', function (): void {
    app(SettingsService::class)->set('integrations.github.token.'.EXT_OWNER, EXT_TOKEN, encrypted: true);

    $available = app(ExtensionInstaller::class)->available();

    expect($available)->toHaveKey(EXT_FOLDER)
        ->and($available[EXT_FOLDER]['owner'])->toBe(EXT_OWNER)
        ->and($available[EXT_FOLDER]['has_token'])->toBeTrue();
});

it('lists available extensions on the Available tab', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(Modules::class, ['tab' => 'available'])
        ->assertSee('Available extensions')
        ->assertSee(EXT_FOLDER);
});

it('clones an extension with the stored github token and redirects', function (): void {
    app(SettingsService::class)->set('integrations.github.token.'.EXT_OWNER, EXT_TOKEN, encrypted: true);
    $this->actingAs(createAdminUser());
    Process::fake();

    Livewire::test(Modules::class)
        ->call('installExtension', EXT_FOLDER)
        ->assertRedirect(route('admin.system.software.modules.index'));

    $expectedAuthHeader = 'http.extraHeader=Authorization: Basic '.base64_encode('x-access-token:'.EXT_TOKEN);

    Process::assertRan(fn ($process): bool => in_array('clone', $process->command, true)
        && in_array(EXT_REPO, $process->command, true)
        && in_array(base_path('extensions/'.EXT_FOLDER), $process->command, true)
        && in_array($expectedAuthHeader, $process->command, true));
});

it('blocks extension install for users without the manage capability', function (): void {
    $this->actingAs(User::factory()->create());
    Process::fake();

    Livewire::test(Modules::class)->call('installExtension', EXT_FOLDER)->assertForbidden();

    Process::assertDidntRun(fn ($process): bool => in_array('clone', $process->command, true));
});
