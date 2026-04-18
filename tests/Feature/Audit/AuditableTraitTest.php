<?php

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Listeners\MutationListener;
use App\Base\Audit\Models\AuditMutation;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

const AUDIT_TEST_TABLE = 'audit_test_models';
const AUDIT_TEST_EMAIL = 'alice@example.com';
const AUDIT_REDACTED_VALUE = '[redacted]';

beforeEach(function (): void {
    Schema::create(AUDIT_TEST_TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->string('api_key')->nullable();
        $table->text('bio')->nullable();
        $table->json('metadata')->nullable();
        $table->string('encrypted_field')->nullable();
        $table->timestamps();
    });

    app()->singleton(RequestContext::class, fn () => new RequestContext(
        correlationId: 'test-correlation-id',
        ipAddress: '127.0.0.1',
        url: 'https://test.example.com/test',
        actorType: PrincipalType::USER->value,
        actorId: 42,
        companyId: 1,
    ));
});

afterEach(function (): void {
    Schema::dropIfExists(AUDIT_TEST_TABLE);
});

/**
 * Flush the audit buffer so entries are persisted.
 */
function flushAuditBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $reflection = new ReflectionClass($buffer);
    $method = $reflection->getMethod('flush');
    $method->invoke($buffer);
}

it('logs field values on model creation', function (): void {
    $model = AuditTestModel::query()->create([
        'name' => 'Alice',
        'email' => AUDIT_TEST_EMAIL,
    ]);

    flushAuditBuffer();

    $mutation = AuditMutation::query()
        ->where('auditable_type', AuditTestModel::class)
        ->where('auditable_id', $model->id)
        ->where('event', 'created')
        ->first();

    expect($mutation)->not->toBeNull();
    expect($mutation->actor_type)->toBe(PrincipalType::USER->value);
    expect($mutation->actor_id)->toBe(42);
    expect($mutation->correlation_id)->toBe('test-correlation-id');

    $newValues = $mutation->new_values;
    expect($newValues['name'])->toBe('Alice');
    expect($newValues['email'])->toBe(AUDIT_TEST_EMAIL);
    expect($mutation->old_values)->toBeNull();
});

it('logs old and new values on update with only dirty fields', function (): void {
    $model = AuditTestModel::query()->create(['name' => 'Alice', 'email' => AUDIT_TEST_EMAIL]);
    flushAuditBuffer();

    $model->update(['name' => 'Bob']);
    flushAuditBuffer();

    $mutation = AuditMutation::query()
        ->where('auditable_id', $model->id)
        ->where('event', 'updated')
        ->first();

    expect($mutation)->not->toBeNull();
    expect($mutation->old_values)->toBe(['name' => 'Alice']);
    expect($mutation->new_values)->toBe(['name' => 'Bob']);
    expect($mutation->old_values)->not->toHaveKey('email');
});

it('logs old values on deletion', function (): void {
    $model = AuditTestModel::query()->create(['name' => 'Alice']);
    flushAuditBuffer();

    $model->delete();
    flushAuditBuffer();

    $mutation = AuditMutation::query()
        ->where('auditable_id', $model->id)
        ->where('event', 'deleted')
        ->first();

    expect($mutation)->not->toBeNull();
    expect($mutation->old_values['name'])->toBe('Alice');
    expect($mutation->new_values)->toBeNull();
});

it('redacts globally configured sensitive fields', function (): void {
    $model = AuditTestModel::query()->create([
        'name' => 'Alice',
        'password' => 'super-secret-hash',
        'api_key' => 'sk-1234567890',
    ]);

    flushAuditBuffer();

    $mutation = AuditMutation::query()
        ->where('auditable_id', $model->id)
        ->where('event', 'created')
        ->first();

    $newValues = $mutation->new_values;
    expect($newValues['password'])->toBe(AUDIT_REDACTED_VALUE);
    expect($newValues['api_key'])->toBe(AUDIT_REDACTED_VALUE);
    expect($newValues['name'])->toBe('Alice');
});

it('excludes created_at and updated_at from diffs', function (): void {
    $model = AuditTestModel::query()->create(['name' => 'Alice']);
    flushAuditBuffer();

    $mutation = AuditMutation::query()
        ->where('auditable_id', $model->id)
        ->where('event', 'created')
        ->first();

    expect($mutation->new_values)->not->toHaveKey('created_at');
    expect($mutation->new_values)->not->toHaveKey('updated_at');
});

it('truncates long text values at the configured default', function (): void {
    config(['audit.truncate_default' => 50]);

    $longText = str_repeat('A', 200);
    $model = AuditTestModel::query()->create(['name' => 'Alice', 'bio' => $longText]);

    flushAuditBuffer();

    $mutation = AuditMutation::query()
        ->where('auditable_id', $model->id)
        ->where('event', 'created')
        ->first();

    $bio = $mutation->new_values['bio'];
    expect($bio)->toContain('[truncated, 200 chars]');
    expect(mb_strlen($bio))->toBeLessThan(200);
});

it('respects model-level auditExclude property', function (): void {
    AuditTestModelWithExclusions::query()->create([
        'name' => 'Alice',
        'metadata' => json_encode(['key' => 'value']),
    ]);

    flushAuditBuffer();

    $mutation = AuditMutation::query()
        ->where('auditable_type', AuditTestModelWithExclusions::class)
        ->where('event', 'created')
        ->first();

    expect($mutation->new_values)->not->toHaveKey('metadata');
    expect($mutation->new_values['name'])->toBe('Alice');
});

it('skips logging when no meaningful changes exist on update', function (): void {
    $model = AuditTestModel::query()->create(['name' => 'Alice']);
    flushAuditBuffer();

    $countBefore = AuditMutation::query()->count();

    $model->save();
    flushAuditBuffer();

    expect(AuditMutation::query()->count())->toBe($countBefore);
});

it('suppresses auditing inside withoutAuditing callback', function (): void {
    $countBefore = AuditMutation::query()->count();

    MutationListener::withoutAuditing(function (): void {
        AuditTestModel::query()->create(['name' => 'Silent']);
    });

    flushAuditBuffer();

    expect(AuditMutation::query()->count())->toBe($countBefore);
});

it('auto-redacts encrypted cast fields', function (): void {
    AuditTestModelWithEncrypted::query()->create([
        'name' => 'Alice',
        'encrypted_field' => 'sensitive-data',
    ]);

    flushAuditBuffer();

    $mutation = AuditMutation::query()
        ->where('auditable_type', AuditTestModelWithEncrypted::class)
        ->where('event', 'created')
        ->first();

    expect($mutation->new_values['encrypted_field'])->toBe(AUDIT_REDACTED_VALUE);
    expect($mutation->new_values['name'])->toBe('Alice');
});

it('skips excluded models from config', function (): void {
    config(['audit.exclude_models' => [AuditTestModel::class]]);

    AuditTestModel::query()->create(['name' => 'Should not be logged']);
    flushAuditBuffer();

    expect(AuditMutation::query()
        ->where('auditable_type', AuditTestModel::class)
        ->count()
    )->toBe(0);
});

// ── Test Models ────────────────────────────────────────────

class AuditTestModel extends Model
{
    protected $table = AUDIT_TEST_TABLE;

    protected $guarded = [];
}

class AuditTestModelWithExclusions extends Model
{
    protected $table = AUDIT_TEST_TABLE;

    protected $guarded = [];

    protected array $auditExclude = ['metadata'];
}

class AuditTestModelWithEncrypted extends Model
{
    protected $table = AUDIT_TEST_TABLE;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'encrypted_field' => 'encrypted',
        ];
    }
}
