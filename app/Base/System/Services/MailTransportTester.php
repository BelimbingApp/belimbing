<?php

namespace App\Base\System\Services;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Exercises the saved SMTP transport with a real send, so an operator learns
 * whether production mail actually works instead of only that a form saved
 * (#376). Always tests SMTP specifically — the point is verifying the SMTP
 * setup, independent of whether "Log only" happens to be the active default.
 *
 * Builds an ad-hoc Mailer from the saved settings via Mail::build() rather
 * than mutating the process-global mail config: a long-running worker must
 * not let one operator's test send change what a concurrent request's real
 * mail uses, the same class of hazard #377 names for tenant identity.
 */
final readonly class MailTransportTester
{
    public function __construct(private MailRuntimeSettings $mail) {}

    /**
     * @return array{ok: bool, category: string, message: string}
     */
    public function send(string $recipient): array
    {
        $config = $this->mail->configuration();
        $username = (string) ($config['mailers.smtp.username'] ?? '');
        $password = (string) ($config['mailers.smtp.password'] ?? '');

        try {
            $mailer = Mail::build([
                'transport' => 'smtp',
                'scheme' => $config['mailers.smtp.scheme'] ?: null,
                'host' => $config['mailers.smtp.host'],
                'port' => $config['mailers.smtp.port'],
                'username' => $username !== '' ? $username : null,
                'password' => $password !== '' ? $password : null,
            ]);
        } catch (Throwable) {
            return [
                'ok' => false,
                'category' => 'configuration',
                'message' => __('Could not build the SMTP transport from saved settings — check host, port, and scheme.'),
            ];
        }

        try {
            $mailer->raw(
                __('This is a test message from :product\'s Email settings page. If you received it, outbound SMTP delivery is working. This confirms the message reached your SMTP server for submission, not that it was placed in an inbox.', ['product' => app(SystemRuntimeSettings::class)->productName()]),
                function ($message) use ($recipient, $config): void {
                    $message->to($recipient)
                        ->from($config['from.address'], $config['from.name'])
                        ->subject(__('Belimbing SMTP test'));
                },
            );
        } catch (TransportExceptionInterface $exception) {
            return [
                'ok' => false,
                'category' => $this->classify($exception),
                'message' => $this->sanitize($exception->getMessage(), $username, $password),
            ];
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'category' => 'unknown',
                'message' => $this->sanitize($exception->getMessage(), $username, $password),
            ];
        }

        return [
            'ok' => true,
            'category' => 'ok',
            'message' => __('Test message submitted to :host:port. Check :recipient for delivery — submission success does not guarantee inbox placement.', [
                'host' => $config['mailers.smtp.host'],
                'port' => $config['mailers.smtp.port'],
                'recipient' => $recipient,
            ]),
        ];
    }

    /**
     * Best-effort classification from the transport exception's own text —
     * Symfony/SMTP servers vary in exact wording, so this is a heuristic to
     * point the operator at the right place, not a guarantee.
     */
    private function classify(TransportExceptionInterface $exception): string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'tls') || str_contains($message, 'ssl') || str_contains($message, 'certificate') => 'tls',
            str_contains($message, 'auth') || str_contains($message, 'credentials') || str_contains($message, '535') => 'authentication',
            str_contains($message, 'sender') || str_contains($message, 'from address') || preg_match('/\b55[013]\b/', $message) === 1 => 'sender_rejected',
            str_contains($message, 'could not connect') || str_contains($message, 'connection') || str_contains($message, 'resolve host') || str_contains($message, 'timed out') => 'connection',
            default => 'unknown',
        };
    }

    /**
     * Defense in depth: SMTP does not echo a password back, and a transport
     * exception's message is Symfony/server-generated text, not saved-setting
     * interpolation — but "never render credentials" (#376) is worth a
     * belt-and-suspenders strip rather than trusting that invariant to hold
     * forever across transport implementations.
     */
    private function sanitize(string $message, string $username, string $password): string
    {
        $redacted = $message;

        foreach ([$password, $username] as $secret) {
            if ($secret !== '') {
                $redacted = str_replace($secret, '[redacted]', $redacted);
            }
        }

        return $redacted;
    }
}
