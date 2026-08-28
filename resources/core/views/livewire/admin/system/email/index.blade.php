<?php

use App\Base\Settings\Support\SettingsFieldValue;
use App\Base\System\Livewire\Email\Index;
use App\Base\System\Services\MailRuntimeSettings;

/** @var Index $this */
/** @var array<string, mixed> $group */
/** @var array<string, mixed> $values */
$mailerFormKey = SettingsFieldValue::formKey(MailRuntimeSettings::MAILER_KEY);
$isLogOnly = ($values[$mailerFormKey] ?? 'log') !== 'smtp';
?>

<div class="space-y-6">
    @if ($isLogOnly)
        <x-ui.alert variant="warning">
            {{ __('Log only is the active delivery mode: password-reset and email-verification messages are written to the application log, not delivered. Switch to SMTP and save to reach real users.') }}
        </x-ui.alert>
    @endif

    @include('livewire.settings.partials.fields-grid', ['group' => $group])

    <section class="space-y-4 border-t border-border-default pt-5" aria-labelledby="email-test-send-heading">
        <div>
            <h3 id="email-test-send-heading" class="text-sm font-medium text-ink">{{ __('Send a test email') }}</h3>
            <p class="mt-1 text-sm text-muted">
                {{ __('Exercises the saved SMTP transport, authentication, TLS negotiation, and sender identity above with a real send — save your changes first. A success here proves submission to your SMTP server, not inbox placement.') }}
            </p>
        </div>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="max-w-sm flex-1">
                <x-ui.input
                    id="email-test-recipient"
                    wire:model="testRecipient"
                    label="{{ __('Send test to') }}"
                    type="email"
                    placeholder="you@example.com"
                    :error="$errors->first('testRecipient')"
                />
            </div>
            <x-ui.button
                type="button"
                variant="secondary"
                wire:click="sendTestEmail"
                wire:loading.attr="disabled"
                wire:target="sendTestEmail"
            >
                <span wire:loading.remove wire:target="sendTestEmail">{{ __('Send test email') }}</span>
                <span wire:loading wire:target="sendTestEmail">{{ __('Sending…') }}</span>
            </x-ui.button>
        </div>

        @if ($lastTestResult !== null)
            <x-ui.alert :variant="$lastTestResult['ok'] ? 'success' : 'error'">
                {{ $lastTestResult['message'] }}
            </x-ui.alert>
        @endif
    </section>
</div>
