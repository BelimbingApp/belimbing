<?php

use App\Base\Database\Exceptions\BridgeImportException;
use App\Base\Database\Exceptions\DevelopmentInstanceRequiredException;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\Bridge\DiagnosticPackageImporter;
use App\Base\Database\Services\Bridge\DiagnosticPackageInbox;
use App\Base\Database\Services\Bridge\DiagnosticRowCapture;
use App\Modules\Core\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    config([
        'app.env' => 'testing',
        'bridge.disk' => 'local',
        'bridge.path_prefix' => 'bridge/diagnostics',
        'bridge.incoming_path_prefix' => 'bridge/incoming',
    ]);
});

/** @return array{parent: string, child: string} */
function createDiagnosticImportTables(string $suffix): array
{
    $parent = 'bridge_import_parents_'.$suffix;
    $child = 'bridge_import_children_'.$suffix;

    Schema::create($parent, function ($table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('label');
    });
    Schema::create($child, function ($table) use ($parent): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('parent_id');
        $table->text('diagnostic_value');
        $table->foreign('parent_id')->references('id')->on($parent);
    });

    TableRegistry::register($parent, 'BridgeTest', 'tests/Feature/Database');
    TableRegistry::register($child, 'BridgeTest', 'tests/Feature/Database');

    return compact('parent', 'child');
}

/** @return array{package_id: string, path: string, payload_sha256: string, total_rows: int, size_bytes: int} */
function createDiagnosticImportPackage(string $table, int|string $id): array
{
    $capture = app(DiagnosticRowCapture::class);
    $preview = $capture->preview($table, [(string) $id]);

    return $capture->capture($table, [(string) $id], 'test-import', $preview['preview_sha256']);
}

it('receives, inspects, and transactionally imports a diagnostic package parents first', function (): void {
    $tables = createDiagnosticImportTables('roundtrip');
    $diagnosticValue = "\u{200F}شركة\u{200B} Aме\u{0301}rica";

    DB::table($tables['parent'])->insert(['id' => 7, 'label' => 'Parent']);
    DB::table($tables['child'])->insert(['id' => 11, 'parent_id' => 7, 'diagnostic_value' => $diagnosticValue]);

    $capture = createDiagnosticImportPackage($tables['child'], 11);
    $rawPackage = (string) Storage::disk('local')->get($capture['path']);

    DB::table($tables['child'])->delete();
    DB::table($tables['parent'])->delete();

    expect(Artisan::call('blb:db:bridge:import-diagnostic', ['path' => $capture['path']]))->toBe(0)
        ->and(DB::table($tables['parent'])->count())->toBe(0)
        ->and(DB::table($tables['child'])->count())->toBe(0)
        ->and(Artisan::output())->toContain('Inspection only');

    $receiptPath = 'bridge/incoming/diagnostic/'.$capture['package_id'].'/receipt.json';
    $receipt = json_decode((string) Storage::disk('local')->get($receiptPath), true);

    expect($receipt['package_sha256'])->toBe(hash('sha256', $rawPackage));

    expect(Artisan::call('blb:db:bridge:import-diagnostic', [
        'path' => $capture['path'],
        '--commit' => true,
    ]))->toBe(0);

    $restored = (array) DB::table($tables['child'])->where('id', 11)->sole();

    expect(DB::table($tables['parent'])->where('id', 7)->value('label'))->toBe('Parent')
        ->and($restored['diagnostic_value'])->toBe($diagnosticValue)
        ->and(bin2hex($restored['diagnostic_value']))->toBe(bin2hex($diagnosticValue));
});

it('rejects Incoming bytes that no longer match the destination receipt', function (): void {
    $tables = createDiagnosticImportTables('tamper');
    DB::table($tables['parent'])->insert(['id' => 1, 'label' => 'Parent']);
    DB::table($tables['child'])->insert(['id' => 2, 'parent_id' => 1, 'diagnostic_value' => 'value']);
    $capture = createDiagnosticImportPackage($tables['child'], 2);
    $receipt = app(DiagnosticPackageInbox::class)->receiveLocal($capture['path']);

    Storage::disk('local')->put($receipt['package_path'], (string) Storage::disk('local')->get($receipt['package_path'])."\n");

    expect(fn () => app(DiagnosticPackageImporter::class)->inspect($capture['package_id']))
        ->toThrow(BridgeImportException::class, 'no longer matches its receipt hash');
});

it('preserves destination values for redacted columns while updating captured fields', function (): void {
    setupAuthzRoles();
    $admin = createAdminUser();
    $user = User::factory()->create([
        'company_id' => $admin->company_id,
        'name' => 'Captured name',
        'password' => Hash::make('captured-password'),
    ]);
    $capture = createDiagnosticImportPackage('users', $user->id);
    $destinationPassword = Hash::make('destination-password');

    $user->update([
        'name' => 'Destination drift',
        'password' => $destinationPassword,
    ]);

    expect(Artisan::call('blb:db:bridge:import-diagnostic', [
        'path' => $capture['path'],
        '--commit' => true,
    ]))->toBe(0);

    $user->refresh();

    expect($user->name)->toBe('Captured name')
        ->and($user->password)->toBe($destinationPassword);
});

it('categorically refuses diagnostic receipt and import outside development', function (): void {
    $tables = createDiagnosticImportTables('production');
    DB::table($tables['parent'])->insert(['id' => 1, 'label' => 'Parent']);
    DB::table($tables['child'])->insert(['id' => 2, 'parent_id' => 1, 'diagnostic_value' => 'value']);
    $capture = createDiagnosticImportPackage($tables['child'], 2);
    config(['app.env' => 'production']);

    expect(fn () => app(DiagnosticPackageInbox::class)->receiveLocal($capture['path']))
        ->toThrow(DevelopmentInstanceRequiredException::class, 'only on a development instance')
        ->and(Storage::disk('local')->files('bridge/incoming'))->toBeEmpty();

    expect(Artisan::call('blb:db:bridge:import-diagnostic', [
        'path' => $capture['path'],
        '--commit' => true,
    ]))->toBe(1);
});
