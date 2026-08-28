<?php

use App\Base\Audit\Models\AuditAction;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\System\Livewire\Email\Index as EmailSettings;
use App\Base\System\Services\MailRuntimeSettings;
use App\Core\User\Models\User;
use Livewire\Livewire;

// AuditBuffer defers persistence to end-of-request (Illuminate's defer()),
// which Livewire::test() never reaches — same reflection flush the existing
// audit UI tests use (tests/Feature/Audit/AuditLogUiTest.php).
function emailSettingsTestFlushAuditBuffer(): void
{
    $buffer = app(AuditBuffer::class);
    $method = (new ReflectionClass($buffer))->getMethod('flush');
    $method->invoke($buffer);
}

beforeEach(function (): void {
    // A closed local port fails fast and deterministically — no real network
    // dependency, and it exercises the real MailTransportTester end to end
    // rather than a fake standing in for it.
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::HOST_KEY, '127.0.0.1');
    $settings->set(MailRuntimeSettings::PORT_KEY, 1);
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'noreply@example.test');
});

test('sending a test email requires a valid recipient address', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(EmailSettings::class)
        ->set('testRecipient', 'not-an-email')
        ->call('sendTestEmail')
        ->assertHasErrors(['testRecipient' => 'email']);
});

test('a test send records an audited action without the SMTP credential', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::USERNAME_KEY, 'audit-secret-username');
    $settings->set(MailRuntimeSettings::PASSWORD_KEY, 'audit-secret-password');

    $this->actingAs(createAdminUser());

    Livewire::test(EmailSettings::class)
        ->set('testRecipient', 'operator@example.test')
        ->call('sendTestEmail')
        ->assertSet('lastTestResult.ok', false)
        ->assertSet('lastTestResult.category', 'connection');

    emailSettingsTestFlushAuditBuffer();

    $action = AuditAction::query()->where('event', 'system.email.test_send')->firstOrFail();
    $encoded = json_encode($action->payload);

    expect($encoded)->not->toContain('audit-secret-username')
        ->and($encoded)->not->toContain('audit-secret-password')
        ->and($action->payload['context']['recipient'])->toBe('operator@example.test');
});

test('repeated test sends are rate-limited', function (): void {
    $this->actingAs(createAdminUser());
    $component = Livewire::test(EmailSettings::class)->set('testRecipient', 'operator@example.test');

    for ($i = 0; $i < 3; $i++) {
        $component->call('sendTestEmail')->assertHasNoErrors('testRecipient');
    }

    $component->call('sendTestEmail');
    expect($component->errors()->first('testRecipient'))->toContain('Too many test sends');
});

test('a non-admin cannot reach the email settings page to send a test email', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.system.email.index'))->assertForbidden();
});

test('a test result is cleared when the settings it verified are saved again', function (): void {
    $this->actingAs(createAdminUser());
    $component = Livewire::test(EmailSettings::class)
        ->set('testRecipient', 'operator@example.test')
        ->call('sendTestEmail');

    expect($component->get('lastTestResult'))->not->toBeNull();

    // Any save — even of an unrelated field — makes the shown result
    // describe a configuration that is no longer necessarily what's saved;
    // it must not keep reading as valid.
    $component->call('save')->assertSet('lastTestResult', null);
});

test('a test result is cleared when defaults are restored', function (): void {
    $this->actingAs(createAdminUser());
    $component = Livewire::test(EmailSettings::class)
        ->set('testRecipient', 'operator@example.test')
        ->call('sendTestEmail');

    expect($component->get('lastTestResult'))->not->toBeNull();

    $component->call('restoreDefaults')->assertSet('lastTestResult', null);
});

test('log-only delivery mode is stated plainly, and disappears once SMTP is saved', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::MAILER_KEY, 'log');

    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.email.index'))
        ->assertSee('not delivered')
        ->assertOk();

    $settings->set(MailRuntimeSettings::MAILER_KEY, 'smtp');

    $this->get(route('admin.system.email.index'))
        ->assertDontSee('not delivered');
});
