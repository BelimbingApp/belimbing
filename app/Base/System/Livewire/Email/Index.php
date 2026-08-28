<?php

namespace App\Base\System\Livewire\Email;

use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Settings\Livewire\SettingsForm;
use App\Base\System\Services\MailTransportTester;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

final class Index extends SettingsForm
{
    /** Deliberately conservative: this sends real mail through a real SMTP relay. */
    private const int TEST_SEND_MAX_ATTEMPTS = 3;

    private const int TEST_SEND_DECAY_SECONDS = 300;

    public string $testRecipient = '';

    /** @var array{ok: bool, category: string, message: string}|null */
    public ?array $lastTestResult = null;

    protected function group(): string
    {
        return 'system_email';
    }

    protected function pageTitle(): string
    {
        return __('Email');
    }

    protected function pageSubtitle(): string
    {
        return __('Configure outgoing delivery and sender identity.');
    }

    /**
     * Exercise the *saved* SMTP transport with a real send — proves the
     * settings work in production, not only that the form validated (#376).
     * Rate-limited (real relay, real quota) and audited without ever
     * recording the SMTP credential.
     */
    public function sendTestEmail(MailTransportTester $tester): void
    {
        $this->authorizeManage();
        $this->validate(['testRecipient' => ['required', 'string', 'email']]);

        $limiterKey = 'system-email-test:'.(Auth::id() ?? request()->ip());

        if (RateLimiter::tooManyAttempts($limiterKey, self::TEST_SEND_MAX_ATTEMPTS)) {
            $this->addError('testRecipient', __(
                'Too many test sends — try again in :seconds seconds.',
                ['seconds' => RateLimiter::availableIn($limiterKey)],
            ));

            return;
        }

        RateLimiter::hit($limiterKey, self::TEST_SEND_DECAY_SECONDS);

        $this->lastTestResult = $tester->send($this->testRecipient);

        app(SemanticActionRecorder::class)->record(
            event: 'system.email.test_send',
            summary: $this->lastTestResult['ok']
                ? __('Sent a test email to verify SMTP setup.')
                : __('SMTP test email failed (:category).', ['category' => $this->lastTestResult['category']]),
            source: __('Email settings'),
            subject: ['name' => 'mail', 'id' => 'smtp-test'],
            surface: 'admin.system.email',
            context: [
                'ok' => $this->lastTestResult['ok'],
                'category' => $this->lastTestResult['category'],
                'recipient' => $this->testRecipient,
            ],
        );

        if ($this->lastTestResult['ok']) {
            $this->notify(__('Test email submitted — check the recipient inbox.'));
        }
    }
}
