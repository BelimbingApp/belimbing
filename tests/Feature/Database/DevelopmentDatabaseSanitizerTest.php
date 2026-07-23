<?php

use App\Base\Schedule\Models\ScheduleSuppression;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use App\Modules\Core\AI\Models\ScheduleDefinition;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.env' => 'testing', 'session.driver' => 'database']);
});

it('previews then neutralizes credentials, framework and AI schedules, and sessions', function (): void {
    $settings = app(SettingsService::class);
    $settings->set('data_share.mirror.provider', 'supabase');
    $settings->set('data_share.mirror.url', 'postgres://production-token@example.test/postgres');
    $settings->set('system.identity.name', 'Keep Me');

    DB::table('sessions')->insert([
        'id' => 'production-session',
        'user_id' => null,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => '{"job":"restored-production-work"}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    Company::provisionLicensee('Sanitizer Company');
    Employee::provisionLara();
    $aiSchedule = ScheduleDefinition::query()->create([
        'company_id' => Company::LICENSEE_ID,
        'employee_id' => null,
        'source' => 'core-ai',
        'source_key' => 'restored-production-task',
        'executor' => ScheduleDefinition::EXECUTOR_HEADLESS_CLI,
        'headless_provider' => 'openai',
        'headless_model' => 'gpt-5',
        'description' => 'Restored production task',
        'execution_payload' => 'Contact an external system',
        'cron_expression' => '* * * * *',
        'timezone' => 'UTC',
        'is_enabled' => true,
        'concurrency_policy' => 'skip',
        'run_requested_at' => now(),
        'next_due_at' => now(),
    ]);

    $event = app(Schedule::class)->command('inspire')->daily();
    $eventKey = app(ScheduleRunRecorder::class)->key($event);

    expect(Artisan::call('blb:db:sanitize-dev'))->toBe(0)
        ->and(Artisan::output())->toContain('Preview only')
        ->and(Setting::query()->where('key', 'data_share.mirror.url')->exists())->toBeTrue()
        ->and(DB::table('sessions')->count())->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($aiSchedule->fresh()->is_enabled)->toBeTrue()
        ->and(ScheduleSuppression::query()->where('key', $eventKey)->exists())->toBeFalse();

    expect(Artisan::call('blb:db:sanitize-dev', ['--commit' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Development sanitization applied')
        ->and(Setting::query()->whereIn('key', ['data_share.mirror.provider', 'data_share.mirror.url'])->count())->toBe(0)
        ->and(Setting::query()->where('key', 'system.identity.name')->value('value'))->toBe('Keep Me')
        ->and(DB::table('sessions')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(ScheduleSuppression::query()->where('key', $eventKey)->exists())->toBeTrue();

    $aiSchedule->refresh();

    expect($aiSchedule->is_enabled)->toBeFalse()
        ->and($aiSchedule->run_requested_at)->toBeNull()
        ->and($aiSchedule->next_due_at)->toBeNull();
});

it('refuses development sanitization in production without touching state', function (): void {
    app(SettingsService::class)->set('data_share.mirror.url', 'postgres://secret@example.test/postgres');
    config(['app.env' => 'production']);

    expect(Artisan::call('blb:db:sanitize-dev', ['--commit' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('only on a development instance')
        ->and(Setting::query()->where('key', 'data_share.mirror.url')->exists())->toBeTrue();
});
