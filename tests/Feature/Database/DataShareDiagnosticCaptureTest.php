<?php

use App\Base\Database\Exceptions\DataShareCaptureException;
use App\Base\Database\Livewire\DatabaseTables\Show;
use App\Base\Database\Livewire\DataShare\Index as DataShareIndex;
use App\Base\Database\Services\DataShare\ColumnRedactor;
use App\Base\Database\Services\DataShare\DependencyClosureResolver;
use App\Base\Database\Services\DataShare\DiagnosticRowCapture;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const DIAGNOSTIC_BRIDGE_PATH = 'data-share/diagnostics';
const OUTSIDE_BRIDGE_PATH = 'backups/important.json';

beforeEach(function (): void {
    Storage::fake('local');
    setupAuthzRoles();
});

function createCaptureTargetUser(array $attributes = []): User
{
    $admin = createAdminUser();
    test()->actingAs($admin);

    return User::factory()->create(array_merge(['company_id' => $admin->company_id], $attributes));
}

/**
 * @param  list<int|string>  $ids
 * @return array{package_id: string, path: string, payload_sha256: string, total_rows: int, size_bytes: int}
 */
function createReviewedDiagnosticCapture(string $table, array $ids): array
{
    $capture = app(DiagnosticRowCapture::class);
    $preview = $capture->preview($table, $ids);

    return $capture->capture($table, $ids, 'test', $preview['preview_sha256']);
}

it('captures selected rows with their referenced parent rows ordered parents first', function (): void {
    $user = createCaptureTargetUser();

    $result = createReviewedDiagnosticCapture('users', [(string) $user->id]);

    $package = json_decode((string) Storage::disk('local')->get($result['path']), true);

    $tableNames = array_column($package['tables'], 'table');
    expect($tableNames)->toContain('users')
        ->and($tableNames)->toContain('companies')
        ->and(array_search('companies', $tableNames, true))
        ->toBeLessThan(array_search('users', $tableNames, true));

    $users = collect($package['tables'])->firstWhere('table', 'users');
    expect(array_column($users['rows'], 'id'))->toContain($user->id);
});

it('redacts secret-named and ciphertext columns from captured rows', function (): void {
    $user = createCaptureTargetUser();

    $result = createReviewedDiagnosticCapture('users', [(string) $user->id]);

    $package = json_decode((string) Storage::disk('local')->get($result['path']), true);
    $users = collect($package['tables'])->firstWhere('table', 'users');

    expect($users['redacted_columns'])->toContain('password');

    foreach ($users['rows'] as $row) {
        expect($row['password'])->toBeNull();

        if (array_key_exists('remember_token', $row)) {
            expect($row['remember_token'])->toBeNull();
        }
    }
});

it('preserves RTL, zero-width, and non-NFC values byte-exact through the package', function (): void {
    $weirdName = "\u{200F}شركة\u{200B} Aме\u{0301}rica"; // RLM + Arabic + ZWSP + combining accent
    $user = createCaptureTargetUser(['name' => $weirdName]);

    $result = createReviewedDiagnosticCapture('users', [(string) $user->id]);

    $package = json_decode((string) Storage::disk('local')->get($result['path']), true);
    $users = collect($package['tables'])->firstWhere('table', 'users');
    $row = collect($users['rows'])->firstWhere('id', $user->id);

    expect($row['name'])->toBe($weirdName)
        ->and(bin2hex($row['name']))->toBe(bin2hex($weirdName));
});

it('marks the package diagnostic and development-import-only with a verifiable payload hash', function (): void {
    $user = createCaptureTargetUser();

    $result = createReviewedDiagnosticCapture('users', [(string) $user->id]);

    $raw = (string) Storage::disk('local')->get($result['path']);
    $package = json_decode($raw, true);

    expect($package['format'])->toBe(DiagnosticRowCapture::FORMAT)
        ->and($package['marker'])->toBe('diagnostic')
        ->and($package['import_policy'])->toBe('development-only')
        ->and($package['source']['driver'])->not->toBe('')
        ->and($package['source'])->toHaveKeys(['encoding', 'collation'])
        ->and($package['selection'])->toMatchArray(['table' => 'users', 'primary_key' => 'id']);

    $payload = json_encode(
        $package['tables'],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
    );
    expect(hash('sha256', $payload))->toBe($package['payload_sha256']);
});

it('creates a package from the table browser selection flow', function (): void {
    $user = createCaptureTargetUser();

    Livewire::test(Show::class, ['tableName' => 'users'])
        ->set('selectedRowIds', [(string) $user->id])
        ->assertSee('Preview package…')
        ->assertDontSee('Copy rows…')
        ->call('openCaptureDialog')
        ->assertSet('showCaptureModal', true)
        ->call('createCapturePackage')
        ->assertSet('showCaptureModal', false)
        ->assertSet('selectedRowIds', [])
        ->assertSet('captureStatusVariant', 'success');

    expect(Storage::disk('local')->files(DIAGNOSTIC_BRIDGE_PATH))->toHaveCount(1);
});

it('warns instead of opening the dialog when nothing is selected', function (): void {
    createCaptureTargetUser();

    Livewire::test(Show::class, ['tableName' => 'users'])
        ->call('openCaptureDialog')
        ->assertSet('showCaptureModal', false)
        ->assertSet('captureStatusVariant', 'warning');
});

it('forbids capture without the Data Share capture capability', function (): void {
    $admin = createAdminUser();
    $plainUser = User::factory()->create(['company_id' => $admin->company_id]);
    $this->actingAs($plainUser);

    Livewire::test(Show::class, ['tableName' => 'users'])
        ->set('selectedRowIds', [(string) $admin->id])
        ->call('openCaptureDialog')
        ->assertForbidden();
});

it('forbids package inventory without the Data Share view capability', function (): void {
    $admin = createAdminUser();
    $plainUser = User::factory()->create(['company_id' => $admin->company_id]);
    $this->actingAs($plainUser);

    $this->get(route('admin.system.data-share.index'))->assertForbidden();
});

it('lists and deletes packages on the data share page', function (): void {
    $user = createCaptureTargetUser();

    $result = createReviewedDiagnosticCapture('users', [(string) $user->id]);

    $this->get(route('admin.system.data-share.index'))
        ->assertOk()
        ->assertSee('Data Share')
        ->assertSee($result['package_id']);

    Livewire::test(DataShareIndex::class)
        ->call('deletePackage', $result['path'])
        ->assertSet('statusVariant', 'success');

    expect(Storage::disk('local')->exists($result['path']))->toBeFalse();
});

it('refuses to delete paths outside the Data Share storage prefix', function (): void {
    createCaptureTargetUser();

    Storage::disk('local')->put(OUTSIDE_BRIDGE_PATH, '{}');

    Livewire::test(DataShareIndex::class)
        ->call('deletePackage', OUTSIDE_BRIDGE_PATH)
        ->assertSet('statusVariant', 'warning');

    expect(Storage::disk('local')->exists(OUTSIDE_BRIDGE_PATH))->toBeTrue();
});

it('forbids deleting a package without the Data Share delete capability', function (): void {
    $user = createCaptureTargetUser();
    $result = createReviewedDiagnosticCapture('users', [(string) $user->id]);
    $plainUser = User::factory()->create(['company_id' => $user->company_id]);
    $this->actingAs($plainUser);

    Livewire::test(DataShareIndex::class)
        ->call('deletePackage', $result['path'])
        ->assertForbidden();

    expect(Storage::disk('local')->exists($result['path']))->toBeTrue();
});

it('refuses to write diagnostic packages to a public disk', function (): void {
    $user = createCaptureTargetUser();
    $capture = app(DiagnosticRowCapture::class);
    $preview = $capture->preview('users', [(string) $user->id]);
    config(['data_share.disk' => 'public']);

    expect(fn () => $capture->capture(
        'users',
        [(string) $user->id],
        'test',
        $preview['preview_sha256'],
    ))->toThrow(DataShareCaptureException::class, 'disk public is public');
});

it('requires the package to match the previewed rows and dependencies', function (): void {
    $user = createCaptureTargetUser();
    $capture = app(DiagnosticRowCapture::class);
    $preview = $capture->preview('users', [(string) $user->id]);

    $user->update(['name' => 'Changed after preview']);

    expect(fn () => $capture->capture(
        'users',
        [(string) $user->id],
        'test',
        $preview['preview_sha256'],
    ))->toThrow(DataShareCaptureException::class, 'changed after preview');

    expect(Storage::disk('local')->files(DIAGNOSTIC_BRIDGE_PATH))->toBeEmpty();
});

it('refuses a table-browser selection changed after preview', function (): void {
    $first = createCaptureTargetUser();
    $second = User::factory()->create(['company_id' => $first->company_id]);

    Livewire::test(Show::class, ['tableName' => 'users'])
        ->set('selectedRowIds', [(string) $first->id])
        ->call('openCaptureDialog')
        ->set('selectedRowIds', [(string) $second->id])
        ->call('createCapturePackage')
        ->assertSet('showCaptureModal', false)
        ->assertSet('captureStatusVariant', 'warning');

    expect(Storage::disk('local')->files(DIAGNOSTIC_BRIDGE_PATH))->toBeEmpty();
});

it('detects Laravel ciphertext stored inside a JSON column value', function (): void {
    $ciphertextAsJson = json_encode(Crypt::encryptString('classified'));

    $result = app(ColumnRedactor::class)->redact('base_settings', [
        ['id' => 1, 'value' => $ciphertextAsJson, 'is_encrypted' => 1],
    ]);

    expect($result['redacted_columns'])->toBe(['value'])
        ->and($result['rows'][0]['value'])->toBeNull();
});

it('resolves composite foreign keys without pulling unrelated parent tuples', function (): void {
    Schema::create('bridge_composite_parents', function ($table): void {
        $table->string('code');
        $table->string('locale');
        $table->string('label');
        $table->primary(['code', 'locale']);
    });

    Schema::create('bridge_composite_children', function ($table): void {
        $table->id();
        $table->string('parent_code');
        $table->string('parent_locale');
        $table->foreign(['parent_code', 'parent_locale'])
            ->references(['code', 'locale'])
            ->on('bridge_composite_parents');
    });

    DB::table('bridge_composite_parents')->insert([
        ['code' => 'A', 'locale' => 'en', 'label' => 'expected'],
        ['code' => 'A', 'locale' => 'ms', 'label' => 'same code'],
        ['code' => 'B', 'locale' => 'en', 'label' => 'same locale'],
    ]);
    DB::table('bridge_composite_children')->insert([
        'id' => 1,
        'parent_code' => 'A',
        'parent_locale' => 'en',
    ]);

    $closure = app(DependencyClosureResolver::class)->resolve('bridge_composite_children', 'id', [1]);
    $parents = collect($closure)->firstWhere('table', 'bridge_composite_parents');

    expect($parents['rows'])->toHaveCount(1)
        ->and($parents['rows'][0]['label'])->toBe('expected');
});
