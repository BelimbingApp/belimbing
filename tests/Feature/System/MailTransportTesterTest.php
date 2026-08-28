<?php

use App\Base\Settings\Contracts\SettingsService;
use App\Base\System\Services\MailRuntimeSettings;
use App\Base\System\Services\MailTransportTester;
use Illuminate\Mail\Mailer;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A Symfony transport double: either "sends" successfully or throws a given
 * exception, with no socket/network involved — the deterministic seam #397's
 * P4 asked for, so success/TLS/authentication/sender-rejection are proven
 * hermetically rather than only the closed-port connection-failure path.
 */
final class FakeMailTransport implements TransportInterface
{
    public ?RawMessage $lastMessage = null;

    public ?Envelope $lastEnvelope = null;

    public function __construct(private readonly ?Throwable $throws = null) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->lastMessage = $message;
        $this->lastEnvelope = $envelope;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return new SentMessage($message, $envelope ?? Envelope::create($message));
    }

    public function __toString(): string
    {
        return 'fake';
    }
}

/**
 * Builds a MailTransportTester whose Mailer is backed by $transport instead
 * of a real SMTP connection — the transport is the only thing swapped, so
 * MailTransportTester's own config-building and result-composing code runs
 * for real.
 */
function fakeMailTransportTester(TransportInterface $transport): MailTransportTester
{
    return new MailTransportTester(
        app(MailRuntimeSettings::class),
        fn (array $config): Mailer => new Mailer('test', app('view'), $transport, app('events')),
    );
}

test('a connection failure is classified and does not leak the configured credential', function (): void {
    $settings = app(SettingsService::class);
    // A closed local port: no network dependency, deterministic and fast —
    // this is a real Symfony TransportExceptionInterface, not a fake one.
    $settings->set(MailRuntimeSettings::HOST_KEY, '127.0.0.1');
    $settings->set(MailRuntimeSettings::PORT_KEY, 1);
    $settings->set(MailRuntimeSettings::USERNAME_KEY, 'super-secret-username');
    $settings->set(MailRuntimeSettings::PASSWORD_KEY, 'super-secret-password');
    $settings->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'noreply@example.test');

    $result = app(MailTransportTester::class)->send('operator@example.test');

    expect($result['ok'])->toBeFalse()
        ->and($result['category'])->toBe('connection')
        ->and($result['message'])->not->toContain('super-secret-username')
        ->and($result['message'])->not->toContain('super-secret-password');
});

test('an unbuildable transport configuration is reported without throwing', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(MailRuntimeSettings::SCHEME_KEY, "not\na-valid-scheme");
    $settings->set(MailRuntimeSettings::HOST_KEY, '127.0.0.1');
    $settings->set(MailRuntimeSettings::PORT_KEY, 1);

    $result = app(MailTransportTester::class)->send('operator@example.test');

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->not->toBe('');
});

test('a successful submission is reported ok with the recipient and no server text', function (): void {
    app(SettingsService::class)->set(MailRuntimeSettings::FROM_ADDRESS_KEY, 'noreply@example.test');
    $transport = new FakeMailTransport;

    $result = fakeMailTransportTester($transport)->send('operator@example.test');

    expect($result['ok'])->toBeTrue()
        ->and($result['category'])->toBe('ok')
        ->and($result['message'])->toContain('operator@example.test')
        ->and($transport->lastEnvelope->getRecipients()[0]->getAddress())->toBe('operator@example.test')
        ->and($transport->lastEnvelope->getSender()->getAddress())->toBe('noreply@example.test');
});

/**
 * Every failure category, proven from a real TransportExceptionInterface
 * whose message contains transformed (base64) synthetic credential material
 * — the exact shape luna's P1 falsified in the previous version. The curated
 * message must never contain it, because it is never interpolated from the
 * exception at all (see MailTransportTester::result()).
 */
test('every failure category returns a curated message that never contains the exception text', function (string $category, string $rawMessage): void {
    $secret = base64_encode('super-secret-password');
    $transport = new FakeMailTransport(new TransportException("{$rawMessage} [ref:{$secret}]"));

    $result = fakeMailTransportTester($transport)->send('operator@example.test');

    expect($result['ok'])->toBeFalse()
        ->and($result['category'])->toBe($category)
        ->and($result['message'])->not->toContain($secret)
        ->and($result['message'])->not->toContain($rawMessage);
})->with([
    'tls' => ['tls', 'TLS handshake failed: certificate verify failed'],
    'authentication' => ['authentication', 'SMTP Error: 535 Authentication credentials invalid'],
    'sender_rejected' => ['sender_rejected', 'Sender address rejected: 550 relaying denied for this domain'],
    'connection' => ['connection', 'Connection could not be established with host smtp.example.test'],
    'unknown' => ['unknown', 'Something the server said that matches none of the known shapes'],
]);
