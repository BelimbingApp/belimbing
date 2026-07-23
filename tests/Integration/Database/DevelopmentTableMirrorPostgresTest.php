<?php

use App\Base\Database\Contracts\DataShareMirrorProcessRunner;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorProcessResult;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\Mirror\DataShareMirrorManager;
use App\Base\Database\Services\DataShare\Mirror\SymfonyDataShareMirrorProcessRunner;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

const MIRROR_PG_PARENT = 'zz_mirror_it_parents';
const MIRROR_PG_CHILD = 'zz_mirror_it_lines';
const MIRROR_PG_EMPTY = 'zz_mirror_it_empty';
const MIRROR_PG_LEGACY = 'zz_mirror_it_legacy';
const MIRROR_PG_CONTROL = 'zz_mirror_it_control';
const MIRROR_PG_DEPENDENT = 'zz_mirror_it_unselected_dependent';
const MIRROR_PG_CYCLE_A = 'zz_mirror_it_cycle_a';
const MIRROR_PG_CYCLE_B = 'zz_mirror_it_cycle_b';
const MIRROR_PG_TABLES = [MIRROR_PG_PARENT, MIRROR_PG_CHILD, MIRROR_PG_EMPTY, MIRROR_PG_LEGACY];
const MIRROR_PG_MODULE_NAME = 'Mirror integration';
const MIRROR_PG_MODULE_PATH = 'app/Modules/Core/User';
const MIRROR_PG_MIGRATION_FILE = '0200_01_20_000000_create_users_table.php';
const MIRROR_PG_LOCAL_INSTANCE_ID = 'mirror-integration-local';
const MIRROR_PG_REMOTE_INSTANCE_ID = 'mirror-integration-remote';
const MIRROR_PG_FAILURE_TRIGGER = 'zz_mirror_it_fail_selected_drop';
const MIRROR_PG_FAILURE_FUNCTION = 'zz_mirror_it_fail_selected_drop';
const MIRROR_PG_POSTCONDITION_TRIGGER = 'zz_mirror_it_suppress_registry';
const MIRROR_PG_POSTCONDITION_FUNCTION = 'zz_mirror_it_suppress_registry';

beforeEach(function (): void {
    if (! mirrorPostgresTestsEnabled()) {
        $this->markTestSkipped('Set BLB_POSTGRES_MIRROR_TESTS=true and provide two isolated PostgreSQL databases.');
    }

    config([
        'app.env' => 'testing',
        'database.default' => 'pgsql',
        'database.connections.data_share_mirror' => mirrorPostgresTargetConfig(),
        'database.connections.mirror_integration_lock_holder' => mirrorPostgresTargetConfig(),
        'data_share.instance.role' => 'development',
        'data_share.mirror.lock_timeout_ms' => 500,
        'data_share.mirror.temp_path' => mirrorPostgresTemporaryDirectory(),
        'settings.cache_ttl' => 0,
    ]);

    DB::purge('pgsql');
    DB::purge('data_share_mirror');
    DB::purge('mirror_integration_lock_holder');

    File::deleteDirectory(mirrorPostgresTemporaryDirectory());
    File::ensureDirectoryExists(mirrorPostgresTemporaryDirectory());
    mirrorResetPostgresFixture();

    app()->instance(SettingsService::class, new DevelopmentTableMirrorIntegrationSettings([
        'data_share.instance.id' => MIRROR_PG_LOCAL_INSTANCE_ID,
        'data_share.instance.role' => 'development',
        'data_share.mirror.url' => mirrorPostgresTargetUrl(),
    ]));
});

afterEach(function (): void {
    if (! mirrorPostgresTestsEnabled()) {
        return;
    }

    mirrorDropFixtureRelations(DB::connection('data_share_mirror'));
    mirrorDropFixtureRelations(DB::connection());
    DB::purge('mirror_integration_lock_holder');
    File::deleteDirectory(mirrorPostgresTemporaryDirectory());
});

it('pushes an explicit mixed selection as complete PostgreSQL table images', function (): void {
    mirrorSeedAuthoritativeFixture(DB::connection(), DB::connection('data_share_mirror'));
    $controlBefore = mirrorControlFingerprint(DB::connection('data_share_mirror'));

    $manager = app(DataShareMirrorManager::class);
    $review = $manager->review('push', MIRROR_PG_TABLES);

    expect(mirrorActionCounts($review->toArray()))->toBe([
        'create' => 2,
        'delete' => 1,
        'replace' => 1,
    ]);

    $manager->execute('push', MIRROR_PG_TABLES);

    mirrorAssertAuthoritativeImage(DB::connection('data_share_mirror'), $controlBefore);
    mirrorAssertNoTransferArtifacts();
});

it('pulls an explicit mixed selection as complete PostgreSQL table images', function (): void {
    mirrorSeedAuthoritativeFixture(DB::connection('data_share_mirror'), DB::connection());
    $controlBefore = mirrorControlFingerprint(DB::connection());

    $manager = app(DataShareMirrorManager::class);
    $review = $manager->review('pull', MIRROR_PG_TABLES);

    expect(mirrorActionCounts($review->toArray()))->toBe([
        'create' => 2,
        'delete' => 1,
        'replace' => 1,
    ]);

    $manager->execute('pull', MIRROR_PG_TABLES);

    mirrorAssertAuthoritativeImage(DB::connection(), $controlBefore);
    mirrorAssertNoTransferArtifacts();
});

it('keeps every connection coordinate out of PostgreSQL process arguments', function (): void {
    mirrorSeedAuthoritativeFixture(DB::connection(), DB::connection('data_share_mirror'));
    $processes = new RecordingMirrorProcessRunner(app(SymfonyDataShareMirrorProcessRunner::class));
    app()->instance(DataShareMirrorProcessRunner::class, $processes);

    app(DataShareMirrorManager::class)->execute('push', MIRROR_PG_TABLES);

    $transfers = array_values(array_filter(
        $processes->calls,
        static fn (array $call): bool => in_array('--format=plain', $call['command'], true)
            || in_array('--no-psqlrc', $call['command'], true),
    ));

    expect($transfers)->toHaveCount(2);
    foreach ($transfers as $call) {
        expect(implode(' ', $call['command']))
            ->not->toContain('--host=', '--port=', '--username=', '--dbname=')
            ->and(array_keys($call['environment']))
            ->toContain('PGHOST', 'PGPORT', 'PGUSER', 'PGDATABASE', 'PGPASSWORD');
    }

    mirrorAssertNoTransferArtifacts();
});

it('rolls back every selected action and removes transfer material when restore fails', function (): void {
    mirrorSeedAuthoritativeFixture(DB::connection(), DB::connection('data_share_mirror'));
    $target = DB::connection('data_share_mirror');
    $selectedBefore = mirrorSelectedDestinationFingerprint($target);
    $controlBefore = mirrorControlFingerprint($target);

    mirrorInstallSelectedDropFailure($target);

    expect(fn () => app(DataShareMirrorManager::class)->execute('push', MIRROR_PG_TABLES))
        ->toThrow(DataShareMirrorException::class);

    expect(mirrorSelectedDestinationFingerprint(DB::connection('data_share_mirror')))->toBe($selectedBefore)
        ->and(mirrorControlFingerprint(DB::connection('data_share_mirror')))->toBe($controlBefore);
    mirrorAssertNoTransferArtifacts();
});

it('verifies selected table postconditions before committing the target transaction', function (): void {
    mirrorSeedAuthoritativeFixture(DB::connection(), DB::connection('data_share_mirror'));
    $target = DB::connection('data_share_mirror');
    // Local-first seeds register create-targets on both endpoints. Remove EMPTY's
    // destination registry row so restore must INSERT it — that is what the
    // suppression trigger intercepts to prove postcondition checking.
    $target->table('base_database_tables')->where('table_name', MIRROR_PG_EMPTY)->delete();
    $selectedBefore = mirrorSelectedDestinationFingerprint($target);
    $controlBefore = mirrorControlFingerprint($target);

    mirrorInstallRegistrySuppression($target);

    expect(fn () => app(DataShareMirrorManager::class)->execute('push', MIRROR_PG_TABLES))
        ->toThrow(DataShareMirrorException::class);

    expect(mirrorSelectedDestinationFingerprint(DB::connection('data_share_mirror')))->toBe($selectedBefore)
        ->and(mirrorControlFingerprint(DB::connection('data_share_mirror')))->toBe($controlBefore);
    mirrorAssertNoTransferArtifacts();
});

it('replaces a fully selected foreign-key cycle without cascade', function (): void {
    mirrorSeedForeignKeyCycle(DB::connection(), DB::connection('data_share_mirror'));
    $manager = app(DataShareMirrorManager::class);
    $tables = [MIRROR_PG_CYCLE_A, MIRROR_PG_CYCLE_B];
    $review = $manager->review('push', $tables);

    expect($review->hasBlockers)->toBeFalse()
        ->and(mirrorActionCounts($review->toArray()))->toBe(['replace' => 2]);

    $manager->execute('push', $tables, $review->stateToken);

    $target = DB::connection('data_share_mirror');
    expect($target->table(MIRROR_PG_CYCLE_A)->value('marker'))->toBe('authority-a')
        ->and($target->table(MIRROR_PG_CYCLE_B)->value('marker'))->toBe('authority-b')
        ->and((int) $target->selectOne(<<<'SQL'
            SELECT count(*) AS aggregate
            FROM pg_constraint
            WHERE contype = 'f'
              AND conrelid IN (
                  'public.zz_mirror_it_cycle_a'::regclass,
                  'public.zz_mirror_it_cycle_b'::regclass
              )
            SQL)->aggregate)->toBe(2);
    mirrorAssertNoTransferArtifacts();
});

it('serializes target mutations with a bounded advisory-lock wait', function (): void {
    mirrorSeedAuthoritativeFixture(DB::connection(), DB::connection('data_share_mirror'));
    $target = DB::connection('data_share_mirror');
    $selectedBefore = mirrorSelectedDestinationFingerprint($target);
    $controlBefore = mirrorControlFingerprint($target);
    $lockHolder = DB::connection('mirror_integration_lock_holder');
    $lockHolder->select('SELECT pg_advisory_lock(1936482669, 20260720)');
    $startedAt = hrtime(true);

    try {
        expect(fn () => app(DataShareMirrorManager::class)->execute('push', MIRROR_PG_TABLES))
            ->toThrow(DataShareMirrorException::class);
    } finally {
        $lockHolder->select('SELECT pg_advisory_unlock(1936482669, 20260720)');
    }

    $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

    expect($elapsedSeconds)->toBeLessThan(10.0)
        ->and(mirrorSelectedDestinationFingerprint(DB::connection('data_share_mirror')))->toBe($selectedBefore)
        ->and(mirrorControlFingerprint(DB::connection('data_share_mirror')))->toBe($controlBefore);
    mirrorAssertNoTransferArtifacts();
});

it('blocks an inbound foreign key from an unselected target table without mutation', function (): void {
    mirrorSeedAuthoritativeFixture(DB::connection(), DB::connection('data_share_mirror'));
    $target = DB::connection('data_share_mirror');
    $selectedBefore = mirrorSelectedDestinationFingerprint($target);
    $controlBefore = mirrorControlFingerprint($target);
    $target->unprepared(sprintf(<<<'SQL'
        CREATE TABLE public.%1$s (
            id integer PRIMARY KEY,
            parent_id integer NOT NULL,
            CONSTRAINT %1$s_parent_fk FOREIGN KEY (parent_id) REFERENCES public.%2$s (id)
        );
        INSERT INTO public.%1$s (id, parent_id) VALUES (1, 1);
        SQL, MIRROR_PG_DEPENDENT, MIRROR_PG_PARENT));
    mirrorRegisterFixtureTable($target, MIRROR_PG_DEPENDENT);

    $manager = app(DataShareMirrorManager::class);
    $review = $manager->review('push', MIRROR_PG_TABLES);
    $parentReview = collect($review->items)->first(
        fn ($item): bool => $item->table === MIRROR_PG_PARENT,
    );

    expect($parentReview)->not->toBeNull()
        ->and($parentReview->action->value)->toBe('blocked')
        ->and(array_column(array_map(fn ($blocker): array => $blocker->toArray(), $parentReview->blockers), 'code'))
        ->toContain('incoming_foreign_key');
    expect(fn () => $manager->execute('push', MIRROR_PG_TABLES))->toThrow(DataShareMirrorException::class);

    expect(mirrorSelectedDestinationFingerprint(DB::connection('data_share_mirror')))->toBe($selectedBefore)
        ->and(mirrorControlFingerprint(DB::connection('data_share_mirror')))->toBe($controlBefore)
        ->and((int) DB::connection('data_share_mirror')->table(MIRROR_PG_DEPENDENT)->count())->toBe(1);
    mirrorAssertNoTransferArtifacts();
});

it('refuses a non-development mirror endpoint before review or export', function (): void {
    DB::connection('data_share_mirror')->table('base_settings')
        ->where('key', 'data_share.instance.role')
        ->update(['value' => json_encode('production', JSON_THROW_ON_ERROR)]);

    $manager = app(DataShareMirrorManager::class);
    $status = $manager->status();

    expect($status->available)->toBeFalse()
        ->and($status->reasonCode)->toBe('remote_role_denied');
    expect(fn () => $manager->review('push', [MIRROR_PG_PARENT]))->toThrow(DataShareMirrorException::class);
    mirrorAssertNoTransferArtifacts();
});

it('refuses a mirror endpoint carrying the local stable instance id', function (): void {
    DB::connection('data_share_mirror')->table('base_settings')
        ->where('key', 'data_share.instance.id')
        ->update(['value' => json_encode(MIRROR_PG_LOCAL_INSTANCE_ID, JSON_THROW_ON_ERROR)]);

    $status = app(DataShareMirrorManager::class)->status();

    expect($status->available)->toBeFalse()
        ->and($status->reachable)->toBeTrue()
        ->and($status->reasonCode)->toBe('self_target');
    mirrorAssertNoTransferArtifacts();
});

it('refuses a mirror endpoint without a stable instance id', function (): void {
    DB::connection('data_share_mirror')->table('base_settings')
        ->where('key', 'data_share.instance.id')
        ->delete();

    $status = app(DataShareMirrorManager::class)->status();

    expect($status->available)->toBeFalse()
        ->and($status->reachable)->toBeTrue()
        ->and($status->reasonCode)->toBe('remote_instance_id_missing');
    mirrorAssertNoTransferArtifacts();
});

it('refuses an incomplete mirror infrastructure before review or export', function (): void {
    DB::connection('data_share_mirror')->statement(
        'ALTER TABLE public.base_database_tables DROP COLUMN stabilized_at',
    );

    $manager = app(DataShareMirrorManager::class);
    $status = $manager->status();

    expect($status->available)->toBeFalse()
        ->and($status->reachable)->toBeTrue()
        ->and($status->reasonCode)->toBe('remote_incompatible');
    expect(fn () => $manager->review('push', [MIRROR_PG_PARENT]))->toThrow(DataShareMirrorException::class);
    mirrorAssertNoTransferArtifacts();
});

it('requires a fresh review when selected table presence drifts', function (): void {
    mirrorSeedAuthoritativeFixture(DB::connection(), DB::connection('data_share_mirror'));
    $manager = app(DataShareMirrorManager::class);
    $review = $manager->review('push', MIRROR_PG_TABLES);
    $controlBefore = mirrorControlFingerprint(DB::connection('data_share_mirror'));

    DB::connection('data_share_mirror')->statement(sprintf(
        'DROP TABLE public."%s"',
        MIRROR_PG_PARENT,
    ));

    expect(fn () => $manager->execute('push', MIRROR_PG_TABLES, $review->stateToken))
        ->toThrow(DataShareMirrorException::class);

    expect(DB::connection('data_share_mirror')->selectOne(
        'SELECT to_regclass(?) AS relation',
        ['public.'.MIRROR_PG_PARENT],
    )->relation)->toBeNull()
        ->and((int) DB::connection('data_share_mirror')->table(MIRROR_PG_LEGACY)->count())->toBe(1)
        ->and(mirrorControlFingerprint(DB::connection('data_share_mirror')))->toBe($controlBefore);
    mirrorAssertNoTransferArtifacts();
});

function mirrorPostgresTestsEnabled(): bool
{
    return filter_var(env('BLB_POSTGRES_MIRROR_TESTS', false), FILTER_VALIDATE_BOOL);
}

/** @return array<string, mixed> */
function mirrorPostgresTargetConfig(): array
{
    return [
        'driver' => 'pgsql',
        'host' => (string) env('MIRROR_TEST_DB_HOST', '127.0.0.1'),
        'port' => (int) env('MIRROR_TEST_DB_PORT', 5432),
        'database' => (string) env('MIRROR_TEST_DB_DATABASE', 'blb_mirror_target'),
        'username' => (string) env('MIRROR_TEST_DB_USERNAME', 'postgres'),
        'password' => (string) env('MIRROR_TEST_DB_PASSWORD', ''),
        'charset' => 'utf8',
        'prefix' => '',
        'prefix_indexes' => true,
        'search_path' => 'public',
        'sslmode' => 'disable',
    ];
}

function mirrorPostgresTargetUrl(): string
{
    $config = mirrorPostgresTargetConfig();

    return sprintf(
        'postgresql://%s:%s@%s:%d/%s?sslmode=disable',
        rawurlencode((string) $config['username']),
        rawurlencode((string) $config['password']),
        (string) $config['host'],
        (int) $config['port'],
        rawurlencode((string) $config['database']),
    );
}

function mirrorPostgresTemporaryDirectory(): string
{
    return storage_path('framework/testing/development-table-mirror-postgres');
}

function mirrorResetPostgresFixture(): void
{
    foreach ([
        [DB::connection(), MIRROR_PG_LOCAL_INSTANCE_ID],
        [DB::connection('data_share_mirror'), MIRROR_PG_REMOTE_INSTANCE_ID],
    ] as [$connection, $instanceId]) {
        mirrorDropFixtureRelations($connection);
        $connection->unprepared(<<<'SQL'
            CREATE TABLE public.base_database_tables (
                id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                table_name varchar(255) NOT NULL UNIQUE,
                module_name varchar(255),
                module_path varchar(255),
                migration_file varchar(255),
                stabilized_at timestamp(0) without time zone,
                stabilized_by bigint,
                created_at timestamp(0) without time zone,
                updated_at timestamp(0) without time zone
            );
            CREATE TABLE public.base_settings (
                id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
                key varchar(255) NOT NULL,
                value jsonb NOT NULL,
                is_encrypted boolean NOT NULL DEFAULT false,
                scope_type varchar(50),
                scope_id bigint,
                created_at timestamp(0) without time zone,
                updated_at timestamp(0) without time zone
            );
            SQL);

        $connection->table('base_settings')->insert([
            [
                'key' => 'data_share.instance.role',
                'value' => json_encode('development', JSON_THROW_ON_ERROR),
                'is_encrypted' => false,
                'scope_type' => null,
                'scope_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'data_share.instance.id',
                'value' => json_encode($instanceId, JSON_THROW_ON_ERROR),
                'is_encrypted' => false,
                'scope_type' => null,
                'scope_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }
}

function mirrorDropFixtureRelations(Connection $connection): void
{
    $connection->statement(sprintf('DROP EVENT TRIGGER IF EXISTS "%s"', MIRROR_PG_FAILURE_TRIGGER));
    $connection->statement(sprintf('DROP FUNCTION IF EXISTS public."%s"()', MIRROR_PG_FAILURE_FUNCTION));

    foreach ([
        MIRROR_PG_CHILD,
        MIRROR_PG_EMPTY,
        MIRROR_PG_PARENT,
        MIRROR_PG_LEGACY,
        MIRROR_PG_DEPENDENT,
        MIRROR_PG_CYCLE_A,
        MIRROR_PG_CYCLE_B,
        MIRROR_PG_CONTROL,
        'base_settings',
        'base_database_tables',
    ] as $table) {
        $connection->statement(sprintf('DROP TABLE IF EXISTS public."%s" CASCADE', $table));
    }

    $connection->statement(sprintf('DROP FUNCTION IF EXISTS public."%s"()', MIRROR_PG_POSTCONDITION_FUNCTION));
}

function mirrorInstallSelectedDropFailure(Connection $connection): void
{
    $connection->unprepared(sprintf(<<<'SQL'
        CREATE FUNCTION public.%1$s() RETURNS event_trigger
        LANGUAGE plpgsql
        AS $function$
        DECLARE
            dropped record;
        BEGIN
            FOR dropped IN SELECT * FROM pg_event_trigger_dropped_objects()
            LOOP
                IF dropped.object_identity LIKE '%%.%2$s' THEN
                    RAISE EXCEPTION 'injected mirror restore failure';
                END IF;
            END LOOP;
        END;
        $function$;
        CREATE EVENT TRIGGER %3$s
        ON sql_drop
        EXECUTE FUNCTION public.%1$s();
        SQL, MIRROR_PG_FAILURE_FUNCTION, MIRROR_PG_PARENT, MIRROR_PG_FAILURE_TRIGGER));
}

function mirrorInstallRegistrySuppression(Connection $connection): void
{
    $connection->unprepared(sprintf(<<<'SQL'
        CREATE FUNCTION public.%1$s() RETURNS trigger
        LANGUAGE plpgsql
        AS $function$
        BEGIN
            IF NEW.table_name = '%2$s' THEN
                RETURN NULL;
            END IF;

            RETURN NEW;
        END;
        $function$;
        CREATE TRIGGER %3$s
        BEFORE INSERT ON public.base_database_tables
        FOR EACH ROW
        EXECUTE FUNCTION public.%1$s();
        SQL, MIRROR_PG_POSTCONDITION_FUNCTION, MIRROR_PG_EMPTY, MIRROR_PG_POSTCONDITION_TRIGGER));
}

function mirrorSeedForeignKeyCycle(Connection $authority, Connection $destination): void
{
    $authority->unprepared(sprintf(<<<'SQL'
        CREATE TABLE public.%1$s (
            id integer PRIMARY KEY,
            b_id integer,
            marker varchar(80) NOT NULL
        );
        CREATE TABLE public.%2$s (
            id integer PRIMARY KEY,
            a_id integer,
            marker varchar(80) NOT NULL
        );
        ALTER TABLE public.%1$s
            ADD CONSTRAINT %1$s_b_fk FOREIGN KEY (b_id) REFERENCES public.%2$s (id);
        ALTER TABLE public.%2$s
            ADD CONSTRAINT %2$s_a_fk FOREIGN KEY (a_id) REFERENCES public.%1$s (id);
        INSERT INTO public.%1$s (id, b_id, marker) VALUES (1, NULL, 'authority-a');
        INSERT INTO public.%2$s (id, a_id, marker) VALUES (1, 1, 'authority-b');
        UPDATE public.%1$s SET b_id = 1 WHERE id = 1;
        SQL, MIRROR_PG_CYCLE_A, MIRROR_PG_CYCLE_B));

    $destination->unprepared(sprintf(<<<'SQL'
        CREATE TABLE public.%1$s (
            id integer PRIMARY KEY,
            b_id integer,
            marker varchar(80) NOT NULL
        );
        CREATE TABLE public.%2$s (
            id integer PRIMARY KEY,
            a_id integer,
            marker varchar(80) NOT NULL
        );
        ALTER TABLE public.%1$s
            ADD CONSTRAINT %1$s_b_fk FOREIGN KEY (b_id) REFERENCES public.%2$s (id);
        ALTER TABLE public.%2$s
            ADD CONSTRAINT %2$s_a_fk FOREIGN KEY (a_id) REFERENCES public.%1$s (id);
        INSERT INTO public.%1$s (id, b_id, marker) VALUES (1, NULL, 'stale-a');
        INSERT INTO public.%2$s (id, a_id, marker) VALUES (1, 1, 'stale-b');
        UPDATE public.%1$s SET b_id = 1 WHERE id = 1;
        SQL, MIRROR_PG_CYCLE_A, MIRROR_PG_CYCLE_B));

    foreach ([$authority, $destination] as $connection) {
        mirrorRegisterFixtureTable($connection, MIRROR_PG_CYCLE_A);
        mirrorRegisterFixtureTable($connection, MIRROR_PG_CYCLE_B);
    }
}

function mirrorSeedAuthoritativeFixture(Connection $authority, Connection $destination): void
{
    $authority->unprepared(sprintf(<<<'SQL'
        CREATE TABLE public.%1$s (
            id bigint GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            code varchar(80) NOT NULL,
            payload bytea,
            metadata jsonb,
            effective_on date,
            amount numeric(16, 4) NOT NULL,
            state varchar(16) NOT NULL DEFAULT 'ready',
            CONSTRAINT %1$s_state_check CHECK (state IN ('ready', 'held'))
        );
        CREATE UNIQUE INDEX %1$s_code_lower_unique ON public.%1$s (lower(code));
        INSERT INTO public.%1$s (id, code, payload, metadata, effective_on, amount, state)
        VALUES (7, 'Éclair شركة', decode('00ff10', 'hex'), '{"nested":{"ready":true}}'::jsonb, DATE '2026-07-20', 12.3400, 'ready');
        ALTER TABLE public.%1$s ALTER COLUMN id RESTART WITH 8;

        CREATE TABLE public.%2$s (
            parent_id bigint NOT NULL,
            line_no smallint NOT NULL,
            note text,
            PRIMARY KEY (parent_id, line_no),
            CONSTRAINT %2$s_parent_fk FOREIGN KEY (parent_id) REFERENCES public.%1$s (id) ON DELETE RESTRICT
        );
        INSERT INTO public.%2$s (parent_id, line_no, note) VALUES (7, 1, 'complete child row');

        CREATE TABLE public.%3$s (
            code varchar(40) PRIMARY KEY,
            quantity integer NOT NULL DEFAULT 0,
            CONSTRAINT %3$s_quantity_check CHECK (quantity >= 0)
        );

        CREATE TABLE public.%4$s (
            id integer PRIMARY KEY,
            marker varchar(80) NOT NULL
        );
        CREATE INDEX %4$s_marker_index ON public.%4$s (marker);
        INSERT INTO public.%4$s (id, marker) VALUES (1, 'authority-control');
        SQL, MIRROR_PG_PARENT, MIRROR_PG_CHILD, MIRROR_PG_EMPTY, MIRROR_PG_CONTROL));

    $destination->unprepared(sprintf(<<<'SQL'
        CREATE TABLE public.%1$s (
            id integer PRIMARY KEY,
            obsolete varchar(40) NOT NULL
        );
        INSERT INTO public.%1$s (id, obsolete) VALUES (1, 'stale-row');

        CREATE TABLE public.%2$s (
            id integer PRIMARY KEY,
            legacy_value text NOT NULL
        );
        INSERT INTO public.%2$s (id, legacy_value) VALUES (9, 'must-be-deleted');

        CREATE TABLE public.%3$s (
            id integer PRIMARY KEY,
            marker varchar(80) NOT NULL
        );
        CREATE INDEX %3$s_marker_index ON public.%3$s (marker);
        INSERT INTO public.%3$s (id, marker) VALUES (1, 'destination-control');
        SQL, MIRROR_PG_PARENT, MIRROR_PG_LEGACY, MIRROR_PG_CONTROL));

    // Local-first catalog: every selected table must be registered on Local even
    // when its relation exists only on the remote (delete on push / create on pull).
    $registered = [...MIRROR_PG_TABLES, MIRROR_PG_CONTROL];
    foreach ([$authority, $destination] as $connection) {
        foreach ($registered as $table) {
            mirrorRegisterFixtureTable($connection, $table);
        }
    }
}

function mirrorRegisterFixtureTable(Connection $connection, string $table): void
{
    $connection->table('base_database_tables')->insert([
        'table_name' => $table,
        'module_name' => MIRROR_PG_MODULE_NAME,
        'module_path' => MIRROR_PG_MODULE_PATH,
        'migration_file' => MIRROR_PG_MIGRATION_FILE,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, int>
 */
function mirrorActionCounts(array $payload): array
{
    $counts = [];
    $visit = function (array $value) use (&$visit, &$counts): void {
        if (isset($value['table'], $value['action']) && is_string($value['action'])) {
            $action = strtolower($value['action']);
            $counts[$action] = ($counts[$action] ?? 0) + 1;
        }

        foreach ($value as $child) {
            if (is_array($child)) {
                $visit($child);
            }
        }
    };

    $visit($payload);
    ksort($counts);

    return $counts;
}

function mirrorAssertAuthoritativeImage(Connection $destination, string $controlBefore): void
{
    $columns = $destination->select(sprintf(<<<'SQL'
        SELECT column_name, data_type, is_identity
        FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = '%s'
        ORDER BY ordinal_position
        SQL, MIRROR_PG_PARENT));
    $columns = array_map(static fn (object $row): array => (array) $row, $columns);

    expect(array_column($columns, 'column_name'))->toBe([
        'id', 'code', 'payload', 'metadata', 'effective_on', 'amount', 'state',
    ])->and(array_column($columns, 'data_type'))->toBe([
        'bigint', 'character varying', 'bytea', 'jsonb', 'date', 'numeric', 'character varying',
    ])->and($columns[0]['is_identity'])->toBe('YES');

    $row = $destination->selectOne(sprintf(
        "SELECT id::text AS id, code, encode(payload, 'hex') AS payload, metadata::text AS metadata, effective_on::text AS effective_on, amount::text AS amount, state FROM public.%s WHERE id = 7",
        MIRROR_PG_PARENT,
    ));

    expect($row)->not->toBeNull()
        ->and($row->id)->toBe('7')
        ->and($row->code)->toBe('Éclair شركة')
        ->and($row->payload)->toBe('00ff10')
        ->and(json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR))->toBe(['nested' => ['ready' => true]])
        ->and($row->effective_on)->toBe('2026-07-20')
        ->and($row->amount)->toBe('12.3400')
        ->and($row->state)->toBe('ready');

    $next = $destination->selectOne(sprintf(
        "INSERT INTO public.%s (code, amount) VALUES ('next', 1.0000) RETURNING id",
        MIRROR_PG_PARENT,
    ));
    expect((int) $next->id)->toBe(8);

    $constraints = $destination->select(sprintf(<<<'SQL'
        SELECT constraint_type.contype, pg_get_constraintdef(constraint_type.oid) AS definition
        FROM pg_constraint AS constraint_type
        WHERE constraint_type.conrelid = 'public.%s'::regclass
        ORDER BY constraint_type.contype, constraint_type.conname
        SQL, MIRROR_PG_CHILD));
    $definitions = array_column(array_map(static fn (object $item): array => (array) $item, $constraints), 'definition');

    expect($definitions)->toContain('PRIMARY KEY (parent_id, line_no)')
        ->and(implode(' ', $definitions))->toContain(sprintf('FOREIGN KEY (parent_id) REFERENCES %s(id)', MIRROR_PG_PARENT));

    $index = $destination->selectOne(
        'SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND indexname = ?',
        ['public', MIRROR_PG_PARENT.'_code_lower_unique'],
    );
    expect($index?->indexdef)->toContain('lower((code)::text)');

    expect((int) $destination->table(MIRROR_PG_CHILD)->count())->toBe(1)
        ->and((int) $destination->table(MIRROR_PG_EMPTY)->count())->toBe(0)
        ->and($destination->selectOne('SELECT to_regclass(?) AS relation', ['public.'.MIRROR_PG_LEGACY])->relation)->toBeNull()
        ->and(mirrorControlFingerprint($destination))->toBe($controlBefore);

    $registered = $destination->table('base_database_tables')
        ->whereIn('table_name', [...MIRROR_PG_TABLES, MIRROR_PG_CONTROL])
        ->orderBy('table_name')
        ->pluck('table_name')
        ->all();
    $expected = [MIRROR_PG_PARENT, MIRROR_PG_CHILD, MIRROR_PG_EMPTY, MIRROR_PG_CONTROL];
    sort($expected);

    expect($registered)->toBe($expected);
}

function mirrorControlFingerprint(Connection $connection): string
{
    $row = $connection->selectOne(sprintf(<<<'SQL'
        SELECT jsonb_build_object(
            'columns', (
                SELECT jsonb_agg(jsonb_build_array(column_name, data_type, is_nullable) ORDER BY ordinal_position)
                FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = '%1$s'
            ),
            'indexes', (
                SELECT jsonb_agg(indexdef ORDER BY indexname)
                FROM pg_indexes
                WHERE schemaname = 'public' AND tablename = '%1$s'
            ),
            'rows', (
                SELECT jsonb_agg(to_jsonb(control_row) ORDER BY id)
                FROM public.%1$s AS control_row
            )
        )::text AS fingerprint
        SQL, MIRROR_PG_CONTROL));

    return (string) $row->fingerprint;
}

function mirrorSelectedDestinationFingerprint(Connection $connection): string
{
    $row = $connection->selectOne(sprintf(<<<'SQL'
        SELECT jsonb_build_object(
            'parent_columns', (
                SELECT jsonb_agg(jsonb_build_array(column_name, data_type, is_nullable) ORDER BY ordinal_position)
                FROM information_schema.columns
                WHERE table_schema = 'public' AND table_name = '%1$s'
            ),
            'parent_rows', (
                SELECT jsonb_agg(to_jsonb(parent_row) ORDER BY id)
                FROM public.%1$s AS parent_row
            ),
            'legacy_rows', (
                SELECT jsonb_agg(to_jsonb(legacy_row) ORDER BY id)
                FROM public.%2$s AS legacy_row
            ),
            'child_relation', to_regclass('public.%3$s'),
            'empty_relation', to_regclass('public.%4$s'),
            'registry', (
                SELECT jsonb_agg(to_jsonb(registry_row) ORDER BY table_name)
                FROM (
                    SELECT table_name, module_name, module_path, migration_file
                    FROM public.base_database_tables
                    WHERE table_name IN ('%1$s', '%2$s', '%3$s', '%4$s')
                    ORDER BY table_name
                ) AS registry_row
            )
        )::text AS fingerprint
        SQL, MIRROR_PG_PARENT, MIRROR_PG_LEGACY, MIRROR_PG_CHILD, MIRROR_PG_EMPTY));

    return (string) $row->fingerprint;
}

function mirrorAssertNoTransferArtifacts(): void
{
    expect(glob(mirrorPostgresTemporaryDirectory().'/blb-mirror-*') ?: [])->toBe([]);
}

final class DevelopmentTableMirrorIntegrationSettings implements SettingsService
{
    /** @param array<string, mixed> $values */
    public function __construct(private array $values) {}

    public function get(string $key, mixed $default = null, ?Scope $scope = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function getMany(array $keys, ?Scope $scope = null): array
    {
        return array_combine(
            $keys,
            array_map(fn (string $key): mixed => $this->get($key, scope: $scope), $keys),
        );
    }

    public function set(string $key, mixed $value, ?Scope $scope = null, bool $encrypted = false): void
    {
        $this->values[$key] = $value;
    }

    public function forget(string $key, ?Scope $scope = null): void
    {
        unset($this->values[$key]);
    }

    public function has(string $key, ?Scope $scope = null): bool
    {
        return array_key_exists($key, $this->values);
    }
}

final class RecordingMirrorProcessRunner implements DataShareMirrorProcessRunner
{
    /** @var list<array{command: list<string>, environment: array<string, string>}> */
    public array $calls = [];

    public function __construct(private readonly DataShareMirrorProcessRunner $delegate) {}

    public function find(string $executable): ?string
    {
        return $this->delegate->find($executable);
    }

    public function run(array $command, array $environment = [], int $timeout = 30): DataShareMirrorProcessResult
    {
        $this->calls[] = ['command' => $command, 'environment' => $environment];

        return $this->delegate->run($command, $environment, $timeout);
    }
}
