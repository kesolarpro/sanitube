<?php

declare(strict_types=1);

namespace SaniTube\Observability\Console;

use Illuminate\Console\Command;
use SaniTube\Observability\Certification\CertifyMail;
use SaniTube\Observability\Certification\MailCertificationOutcome;

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
 * CFG-003 moved the sending itself into {@see CertifyMail}, so that the same
 * act performed from the settings screen is the same code and not a second
 * implementation that eventually disagrees. What stays here is the console's
 * own job: reading an argument and writing sentences.
 */
final class MailCertifyCommand extends Command
{
    protected $signature = 'sanitube:mail:certify
                            {address : Where to send the one certification message}';

    protected $description = 'Send one real message to prove the configured mailer delivers';

    public function handle(CertifyMail $mail): int
    {
        $address = trim((string) $this->argument('address'));

        return match ($mail->send($address)) {
            MailCertificationOutcome::NoRecipient => $this->refuse('That is not an email address.', self::INVALID),

            MailCertificationOutcome::NotConfigured => $this->refuse(
                sprintf(
                    'Mailer [%s] does not deliver anything, so there is nothing to certify. Configure '
                        .'MAIL_MAILER first — the doctor explains what is missing.',
                    (string) config('mail.default', 'log'),
                ),
                self::FAILURE,
            ),

            // The transport's own words carry hosts and sometimes credentials;
            // the doctor and the mail log are where the detail lives.
            MailCertificationOutcome::Refused => $this->refuse(
                'The transport refused the message. Check the MAIL_ settings and the laravel.log for the exchange.',
                self::FAILURE,
            ),

            MailCertificationOutcome::Accepted => $this->accepted($address),
        };
    }

    private function accepted(string $address): int
    {
        $this->info(sprintf('Sent to %s and accepted by the transport.', $address));
        $this->line('Acceptance is what SMTP can promise. If it landed in spam, that is DNS (SPF/DKIM/DMARC), not SaniTube.');

        return self::SUCCESS;
    }

    private function refuse(string $message, int $code): int
    {
        $this->error($message);

        return $code;
    }
}
