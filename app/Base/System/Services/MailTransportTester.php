<?php

namespace App\Base\System\Services;

use Closure;
use Illuminate\Contracts\Mail\Mailer;
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
    /**
     * @var (Closure(array<string, mixed>): Mailer)|null test seam only — null
     *                                                   in production, where Mail::build() talks to a real transport
     */
    private ?Closure $mailerBuilder;

    /**
     * @param  (Closure(array<string, mixed>): Mailer)|null  $mailerBuilder
     */
    public function __construct(
        private MailRuntimeSettings $mail,
        ?Closure $mailerBuilder = null,
    ) {
        $this->mailerBuilder = $mailerBuilder;
    }

    /**
     * @return array{ok: bool, category: string, message: string}
     */
    public function send(string $recipient): array
    {
        $config = $this->mail->configuration();
        $username = (string) ($config['mailers.smtp.username'] ?? '');
        $password = (string) ($config['mailers.smtp.password'] ?? '');
        $build = $this->mailerBuilder ?? static fn (array $mailerConfig): Mailer => Mail::build($mailerConfig);

        try {
            $mailer = $build([
                'transport' => 'smtp',
                'scheme' => $config['mailers.smtp.scheme'] ?: null,
                'host' => $config['mailers.smtp.host'],
                'port' => $config['mailers.smtp.port'],
                'username' => $username !== '' ? $username : null,
                'password' => $password !== '' ? $password : null,
            ]);
        } catch (Throwable) {
            return $this->result('configuration', false);
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
            return $this->result($this->classify($exception), false);
        } catch (Throwable) {
            return $this->result('unknown', false);
        }

        return $this->result('ok', true, $config, $recipient);
    }

    /**
     * Best-effort classification from the transport exception's own text —
     * Symfony/SMTP servers vary in exact wording, so this is a heuristic to
     * point the operator at the right place, not a guarantee. The raw text
     * this reads is used *only* to pick a category here; it is never
     * returned — see result().
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
     * Every returned message is composed entirely by this application from a
     * fixed set — never interpolated from a transport exception's own text.
     * A denylist that strips known secrets from server-controlled text is
     * unwinnable (the SMTP peer chooses the encoding, not us — a base64 or
     * quoted-printable credential survives a literal-substring strip); an
     * allow-list of our own strings cannot leak what it never contains, no
     * matter what the server said (luna's #397 P1).
     *
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, category: string, message: string}
     */
    private function result(string $category, bool $ok, array $config = [], string $recipient = ''): array
    {
        $message = match ($category) {
            'ok' => __('Test message submitted to :host:port. Check :recipient for delivery — submission success does not guarantee inbox placement.', [
                'host' => $config['mailers.smtp.host'] ?? '',
                'port' => $config['mailers.smtp.port'] ?? '',
                'recipient' => $recipient,
            ]),
            'connection' => __('Could not connect to the SMTP server. Check the host and port, and that this deployment can reach it over the network.'),
            'tls' => __('The TLS/SSL handshake failed. Check the scheme setting against what your provider requires.'),
            'authentication' => __('The SMTP server rejected the saved username or password.'),
            'sender_rejected' => __('The SMTP server rejected the sender address. Check that your provider has authorized this From address to send.'),
            'configuration' => __('Could not build the SMTP transport from saved settings — check host, port, and scheme.'),
            default => __('The test message could not be sent. Check the saved SMTP settings.'),
        };

        return ['ok' => $ok, 'category' => $category, 'message' => $message];
    }
}
