<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Support\SettingsFieldValue;
use App\Base\System\Enums\MailPurpose;
use App\Base\System\Livewire\Email\Index as EmailSettings;
use App\Base\System\Services\MailRuntimeSettings;
use App\Base\System\Services\SystemRuntimeSettings;
use App\Core\User\Models\User;
use App\Core\User\Notifications\ResetPasswordNotification;
use App\Core\User\Notifications\VerifyEmailNotification;
use Livewire\Livewire;

test('an unconfigured purpose falls back to the global sender identity', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'noreply@global.test');
    $settings->set(MailRuntimeSettings::FROM_NAME_KEY, 'Global Sender');

    $identity = app(MailRuntimeSettings::class)->effectiveIdentity(MailPurpose::AccountSecurity);

    expect($identity)->toBe([
        'address' => 'noreply@global.test',
        'name' => 'Global Sender',
        'reply_to' => null,
    ]);
});

test('a purpose override wins over the global sender for that purpose only', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'noreply@global.test');
    $settings->set(MailRuntimeSettings::FROM_NAME_KEY, 'Global Sender');
    $settings->set(MailRuntimeSettings::purposeFromAddressKey(MailPurpose::AccountSecurity), 'accounts@global.test');
    $settings->set(MailRuntimeSettings::purposeFromNameKey(MailPurpose::AccountSecurity), 'Account Security');
    $settings->set(MailRuntimeSettings::purposeReplyToKey(MailPurpose::AccountSecurity), 'support@global.test');

    $resolver = app(MailRuntimeSettings::class);

    expect($resolver->effectiveIdentity(MailPurpose::AccountSecurity))->toBe([
        'address' => 'accounts@global.test',
        'name' => 'Account Security',
        'reply_to' => 'support@global.test',
    ]);

    // The other purpose is untouched — an override on one purpose must not
    // leak into another's resolution.
    expect($resolver->effectiveIdentity(MailPurpose::Notifications))->toBe([
        'address' => 'noreply@global.test',
        'name' => 'Global Sender',
        'reply_to' => null,
    ]);
});

test('a From name falls back through purpose override, then global name, then the product name', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'noreply@global.test');
    $settings->set(SystemRuntimeSettings::PRODUCT_NAME_KEY, 'Acme Platform');
    // No global From name set, and no purpose name/address override — the
    // address falls back to global while the name falls two levels further,
    // to the product name. Independent chains, exercised together.
    $settings->set(MailRuntimeSettings::purposeReplyToKey(MailPurpose::AccountSecurity), 'support@global.test');

    $identity = app(MailRuntimeSettings::class)->effectiveIdentity(MailPurpose::AccountSecurity);

    expect($identity['address'])->toBe('noreply@global.test')
        ->and($identity['name'])->toBe('Acme Platform')
        ->and($identity['reply_to'])->toBe('support@global.test');
});

test('password reset mail uses the account-security override when one is set', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'noreply@global.test');
    $settings->set(MailRuntimeSettings::purposeFromAddressKey(MailPurpose::AccountSecurity), 'accounts@global.test');
    $settings->set(MailRuntimeSettings::purposeFromNameKey(MailPurpose::AccountSecurity), 'Account Security');
    $settings->set(MailRuntimeSettings::purposeReplyToKey(MailPurpose::AccountSecurity), 'support@global.test');

    $user = User::factory()->create();
    $message = (new ResetPasswordNotification('a-token'))->toMail($user);

    expect($message->from)->toBe(['accounts@global.test', 'Account Security'])
        ->and($message->replyTo)->toBe([['support@global.test', null]]);
});

test('password reset mail falls back to the global sender when no override is set', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'noreply@global.test');
    $settings->set(MailRuntimeSettings::FROM_NAME_KEY, 'Global Sender');

    $user = User::factory()->create();
    $message = (new ResetPasswordNotification('a-token'))->toMail($user);

    expect($message->from)->toBe(['noreply@global.test', 'Global Sender'])
        ->and($message->replyTo)->toBe([]);
});

test('email verification mail uses the same account-security identity as password reset', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'noreply@global.test');
    $settings->set(MailRuntimeSettings::purposeFromAddressKey(MailPurpose::AccountSecurity), 'accounts@global.test');
    $settings->set(MailRuntimeSettings::purposeFromNameKey(MailPurpose::AccountSecurity), 'Account Security');

    $user = User::factory()->unverified()->create();
    $message = (new VerifyEmailNotification)->toMail($user);

    expect($message->from)->toBe(['accounts@global.test', 'Account Security']);
});

test('the notifications purpose exists and falls back to the global sender but has no active consumer', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'noreply@global.test');
    $settings->set(MailRuntimeSettings::FROM_NAME_KEY, 'Global Sender');

    // No override set for the notifications purpose — proves the contract is
    // ready without requiring configuration, per #377's acceptance: "Notification
    // identity is not presented as required while the platform has no email
    // notification consumer."
    $identity = app(MailRuntimeSettings::class)->effectiveIdentity(MailPurpose::Notifications);

    expect($identity['address'])->toBe('noreply@global.test')
        ->and($identity['name'])->toBe('Global Sender');
});

test('the email settings page explains the sender-purpose distinction and does not require notification fields', function (): void {
    $this->actingAs(createAdminUser());

    $this->get(route('admin.system.email.index'))
        ->assertOk()
        ->assertSee('Global sender')
        ->assertSee('Account security')
        ->assertSee('Application notifications')
        ->assertSee('not yet used');
});

test('saving an account-security override persists and round-trips through the settings page', function (): void {
    $address = SettingsFieldValue::formKey(MailRuntimeSettings::purposeFromAddressKey(MailPurpose::AccountSecurity));
    $name = SettingsFieldValue::formKey(MailRuntimeSettings::purposeFromNameKey(MailPurpose::AccountSecurity));
    $replyTo = SettingsFieldValue::formKey(MailRuntimeSettings::purposeReplyToKey(MailPurpose::AccountSecurity));

    $this->actingAs(createAdminUser());

    Livewire::test(EmailSettings::class)
        ->set("values.{$address}", 'accounts@example.test')
        ->set("values.{$name}", 'Example Accounts')
        ->set("values.{$replyTo}", 'support@example.test')
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    expect($settings->get(MailRuntimeSettings::purposeFromAddressKey(MailPurpose::AccountSecurity)))->toBe('accounts@example.test')
        ->and($settings->get(MailRuntimeSettings::purposeFromNameKey(MailPurpose::AccountSecurity)))->toBe('Example Accounts')
        ->and($settings->get(MailRuntimeSettings::purposeReplyToKey(MailPurpose::AccountSecurity)))->toBe('support@example.test');

    Livewire::test(EmailSettings::class)
        ->assertSet("values.{$address}", 'accounts@example.test')
        ->assertSet("values.{$name}", 'Example Accounts')
        ->assertSet("values.{$replyTo}", 'support@example.test');
});

test('leaving every purpose field blank saves without error and forgets no globally-required setting', function (): void {
    $this->actingAs(createAdminUser());

    Livewire::test(EmailSettings::class)
        ->call('save')
        ->assertHasNoErrors();

    $settings = app(SettingsService::class);
    expect($settings->has(MailRuntimeSettings::purposeFromAddressKey(MailPurpose::AccountSecurity)))->toBeFalse()
        ->and($settings->has(MailRuntimeSettings::purposeFromAddressKey(MailPurpose::Notifications)))->toBeFalse();
});
