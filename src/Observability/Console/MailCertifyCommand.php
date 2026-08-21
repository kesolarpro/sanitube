<?php

declare(strict_types=1);

namespace SaniTube\Observability\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use SaniTube\Observability\Certification\CertificationLedger;
use SaniTube\Observability\Certification\ProviderStandings;
use Throwable;

/**
 * One real message, because "configured" and "delivers" are different facts.
 *
 * An SMTP block full of plausible values passes every configuration check
 * and still bounces the first password reset — wrong port, blocked relay,
 * an app password that was revoked. The only proof is a delivery, so this
 * sends exactly one, to an address the operator names, only when the
 * operator asks. Nothing schedules it; certification mail sent on a timer
 * would be a heartbeat nobody reads decaying into spam.
 *
 * A send the transport accepted becomes a ledger record fingerprinted to
 * the mailer configuration — change the host or the transport and the
 * standing quietly returns to CONFIGURED_UNCERTIFIED. Acceptance is what
 * SMTP can promise; if the message lands in spam, that is between the
 * operator and their DNS records, and the command says so.
 */
final class MailCertifyCommand extends Command
{
    protected $signature = 'sanitube:mail:certify
                            {address : Where to send the one certification message}';

    protected $description = 'Send one real message to prove the configured mailer delivers';

    public function handle(CertificationLedger $ledger): int
    {
        $address = trim((string) $this->argument('address'));

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('That is not an email address.');

            return self::INVALID;
        }

        $mailer = (string) config('mail.default', 'log');
        $transport = (string) config(sprintf('mail.mailers.%s.transport', $mailer), $mailer);

        if (in_array($transport, ['log', 'array'], true)) {
            $this->error(sprintf(
                'Mailer [%s] does not deliver anything, so there is nothing to certify. Configure '
                    .'MAIL_MAILER first — the doctor explains what is missing.',
                $mailer,
            ));

            return self::FAILURE;
        }

        try {
            Mail::raw(
                'This is the one SaniTube mail certification message. Receiving it proves the '
                    .'configured mailer delivers; nothing further will be sent.',
                static function ($message) use ($address): void {
                    $message->to($address)->subject('SaniTube mail certification');
                },
            );
        } catch (Throwable) {
            // The transport's own words carry hosts and sometimes credentials;
            // the doctor and the mail log are where the detail lives.
            $this->error('The transport refused the message. Check the MAIL_ settings and the laravel.log for the exchange.');

            return self::FAILURE;
        }

        $ledger->record('mail', self::fingerprint());

        $this->info(sprintf('Sent to %s and accepted by the transport.', $address));
        $this->line('Acceptance is what SMTP can promise. If it landed in spam, that is DNS (SPF/DKIM/DMARC), not SaniTube.');

        return self::SUCCESS;
    }

    public static function fingerprint(): string
    {
        $mailer = (string) config('mail.default', 'log');

        return ProviderStandings::fingerprint([
            $mailer,
            (string) config(sprintf('mail.mailers.%s.transport', $mailer)),
            (string) config(sprintf('mail.mailers.%s.host', $mailer)),
            (string) config(sprintf('mail.mailers.%s.port', $mailer)),
        ]);
    }
}
