<?php

use App\Base\Audit\Listeners\MutationListener;
use App\Base\Audit\Livewire\AuditLog\SourceHistory;
use App\Base\Audit\Models\AuditMutation;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Livewire\ComponentDiscoveryService;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use App\Base\Settings\Support\SettingsFieldValue;
use App\Base\Settings\Support\SettingSubject;
use App\Base\System\Livewire\Email\Index as EmailSettings;
use App\Base\System\Livewire\Settings\General;
use App\Base\System\Services\MailRuntimeSettings;
use App\Base\System\Services\RuntimeConfigurationApplier;
use App\Base\System\Services\SystemRuntimeSettings;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function runtimeSettingsUiFlushAuditBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $reflection = new ReflectionClass($buffer);
    $reflection->getMethod('flush')->invoke($buffer);
}

/** @return list<array{name: string, id: string}> */
function runtimeSettingsUiEmailSubjects(): array
{
    $keys = collect(config('settings.editable.system_email.fields', []))
        ->pluck('key')
        ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
        ->values()
        ->all();

    return SettingSubject::handles($keys);
}

it('lets an authorized operator save and restore system defaults', function (): void {
    $settings = app(SettingsService::class);
    $name = SettingsFieldValue::formKey(SystemRuntimeSettings::PRODUCT_NAME_KEY);
    $lifetime = SettingsFieldValue::formKey(SystemRuntimeSettings::SESSION_LIFETIME_KEY);

    $this->actingAs(createAdminUser());

    Livewire::test(General::class)
        ->set("values.{$name}", 'Belimbing Operations')
        ->set("values.{$lifetime}", '240')
        ->call('save')
        ->assertHasNoErrors();

    expect($settings->get(SystemRuntimeSettings::PRODUCT_NAME_KEY))->toBe('Belimbing Operations')
        ->and($settings->get(SystemRuntimeSettings::SESSION_LIFETIME_KEY))->toBe(240);

    Livewire::test(General::class)
        ->call('restoreDefaults')
        ->assertSet("values.{$name}", 'Belimbing')
        ->assertSet("values.{$lifetime}", 120);

    expect($settings->has(SystemRuntimeSettings::PRODUCT_NAME_KEY))->toBeFalse()
        ->and($settings->has(SystemRuntimeSettings::SESSION_LIFETIME_KEY))->toBeFalse();
});

it('enforces the settings group capability at the page boundary', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.system.settings.index'))->assertForbidden();
});

it('renders the email settings page through its registered route', function (): void {
    app(SettingsService::class)->set(MailRuntimeSettings::MAILER_KEY, 'smtp');

    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.email.index'))
        ->assertOk()
        ->assertSee('SMTP host')
        ->assertSee('SMTP port')
        ->assertSee('SMTP username')
        ->assertSee('SMTP password')
        ->assertSee('History')
        ->assertDontSee('search=setting%23mail.mailer', false)
        ->assertDontSee('Use Gmail SMTP')
        ->assertDontSee('Use Cloudflare SMTP');

    expect(app(ComponentDiscoveryService::class)->discover())
        ->toHaveKey('app.base.system.livewire.email', EmailSettings::class);
});

it('hides SMTP fields while Log only is the active delivery mode', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.email.index'))
        ->assertOk()
        ->assertDontSee('SMTP host')
        ->assertDontSee('SMTP username')
        ->assertDontSee('SMTP password')
        ->assertDontSee('Send a test email');
});

it('derives an honest bounded history set for every setting shown by the email form', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(EmailSettings::class)
        ->assertViewHas('historySubjects', runtimeSettingsUiEmailSubjects())
        ->assertViewHas('historyCapability', 'admin.system.email.manage')
        ->assertViewHas('historySubjectLabel', count(runtimeSettingsUiEmailSubjects()).' settings');

    Livewire::test(SourceHistory::class, [
        'title' => 'History for Email',
        'subjects' => runtimeSettingsUiEmailSubjects(),
        'subjectLabel' => count(runtimeSettingsUiEmailSubjects()).' settings',
        'sourceCapability' => 'admin.system.email.manage',
    ])->call('open')
        ->assertSet('sourceHistoryDrawerOpen', true)
        ->assertSet('sourceHistorySubjectLabel', count(runtimeSettingsUiEmailSubjects()).' settings')
        ->assertSet('sourceHistoryAllUrl', '')
        ->assertSee(count(runtimeSettingsUiEmailSubjects()).' settings')
        ->assertDontSee('Data Mutations');
});

it('hides email history when the operator lacks Audit Log access', function (): void {
    $user = MutationListener::withoutAuditing(function (): User {
        $company = Company::factory()->create();

        return User::factory()->create(['company_id' => $company->id]);
    });

    PrincipalCapability::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => 'admin.system.email.manage',
        'is_allowed' => true,
    ]);

    $this->actingAs($user)
        ->get(route('admin.system.email.index'))
        ->assertOk()
        ->assertDontSeeHtml('wire:click="open"');
});

it('limits email history to its settings and redacts encrypted values through save and restore', function (): void {
    $actor = createAdminUser();
    $host = SettingsFieldValue::formKey(MailRuntimeSettings::HOST_KEY);
    $password = SettingsFieldValue::formKey(MailRuntimeSettings::PASSWORD_KEY);
    $secret = 'history-secret-'.str()->random(16);

    $this->actingAs($actor);

    app(SettingsService::class)->set(SystemRuntimeSettings::PRODUCT_NAME_KEY, 'Unrelated history value');

    Livewire::test(EmailSettings::class)
        ->set("values.{$host}", 'smtp.history.example')
        ->set("values.{$password}", $secret)
        ->call('save')
        ->assertHasNoErrors()
        ->call('restoreDefaults')
        ->assertHasNoErrors();

    runtimeSettingsUiFlushAuditBuffer();

    $emailHistory = Livewire::test(SourceHistory::class, [
        'title' => 'History for Email',
        'subjects' => runtimeSettingsUiEmailSubjects(),
        'subjectLabel' => count(runtimeSettingsUiEmailSubjects()).' settings',
        'sourceCapability' => 'admin.system.email.manage',
    ])->call('open')
        ->assertSet('sourceHistoryDrawerOpen', true)
        ->assertSee('mail.smtp.host')
        ->assertSee('smtp.history.example')
        ->assertSee('mail.smtp.password')
        ->assertSee('[redacted]')
        ->assertDontSee($secret)
        ->assertDontSee('Unrelated history value');

    expect($emailHistory->get('sourceHistory.total'))->toBe(10)
        ->and(AuditMutation::query()
            ->where('subject_name', 'setting')
            ->whereIn('subject_id', array_column(runtimeSettingsUiEmailSubjects(), 'id'))
            ->pluck('event')
            ->all())
        ->toContain('created', 'deleted')
        ->and(AuditMutation::query()
            ->where('subject_name', 'setting')
            ->where('subject_id', MailRuntimeSettings::PASSWORD_KEY)
            ->get()
            ->flatMap(fn (AuditMutation $mutation): array => [$mutation->old_values, $mutation->new_values])
            ->flatten()
            ->filter(fn (mixed $value): bool => is_string($value))
            ->contains(fn (string $value): bool => str_contains($value, $secret)))
        ->toBeFalse()
        ->and(Setting::query()
            ->whereIn('key', array_column(runtimeSettingsUiEmailSubjects(), 'id'))
            ->exists())
        ->toBeFalse();
});

it('stores mail credentials encrypted and offers reveal controls for saved secrets', function (): void {
    $username = SettingsFieldValue::formKey(MailRuntimeSettings::USERNAME_KEY);
    $password = SettingsFieldValue::formKey(MailRuntimeSettings::PASSWORD_KEY);

    app(SettingsService::class)->set(MailRuntimeSettings::MAILER_KEY, 'smtp');
    $this->actingAs(createAdminUser());

    Livewire::test(EmailSettings::class)
        ->set("values.{$username}", 'smtp-user')
        ->set("values.{$password}", 'smtp-password')
        ->call('save')
        ->assertHasNoErrors();

    $rows = DB::table('base_settings')
        ->whereIn('key', [MailRuntimeSettings::USERNAME_KEY, MailRuntimeSettings::PASSWORD_KEY])
        ->get();

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect((bool) $row->is_encrypted)->toBeTrue()
            ->and((string) $row->value)->not->toContain('smtp-');
    }

    Livewire::test(EmailSettings::class)
        ->assertSet("values.{$username}", 'smtp-user')
        ->assertSet("values.{$password}", 'smtp-password')
        ->assertSee('Show secret');
});

it('projects declared system and mail settings into framework runtime config', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(SystemRuntimeSettings::PRODUCT_NAME_KEY, 'Belimbing Runtime');
    $settings->set(SystemRuntimeSettings::SESSION_LIFETIME_KEY, 360);
    $settings->set(MailRuntimeSettings::MAILER_KEY, 'smtp');
    $settings->set(MailRuntimeSettings::HOST_KEY, 'smtp.example.test');
    $settings->set(MailRuntimeSettings::PORT_KEY, 587);
    $settings->set(MailRuntimeSettings::USERNAME_KEY, 'runtime-user');
    $settings->set(MailRuntimeSettings::PASSWORD_KEY, 'runtime-password');
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'hello@example.test');

    app(RuntimeConfigurationApplier::class)->apply();

    expect(config('app.name'))->toBe('Belimbing Runtime')
        ->and(config('session.lifetime'))->toBe(360)
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.test')
        ->and(config('mail.mailers.smtp.port'))->toBe(587)
        ->and(config('mail.mailers.smtp.username'))->toBe('runtime-user')
        ->and(config('mail.mailers.smtp.password'))->toBe('runtime-password')
        ->and(config('mail.from.address'))->toBe('hello@example.test')
        ->and(config('mail.from.name'))->toBe('Belimbing Runtime');
});
