<?php

declare(strict_types=1);

namespace SaniTube\Observability\Certification;

use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Send one real message, and record it if the transport takes it.
 *
 * CFG-003. This was written inside `sanitube:mail:certify` and stayed there,
 * which meant the only way to find out whether password resets would arrive
 * was SSH. Extracting it is not refactoring for its own sake: the alternative
 * to one implementation is two, and the second is the copy that eventually
 * disagrees — a screen saying mail works while the deploy gate says it does
 * not.
 *
 * **Acceptance is what SMTP can promise.** A transport that takes the message
 * has done everything it can be asked to do; whether it landed in an inbox or
 * a spam folder is SPF, DKIM and DMARC, which are the operator's DNS records
 * and not this platform's business. The vocabulary says "accepted" rather than
 * "delivered" for exactly that reason.
 *
 * **Nothing the transport says is repeated.** A refusal quotes the host it
 * could not authenticate against and, with some relays, the username it tried.
 * The reason travels as one of four words; the exchange stays in the log,
 * where somebody already trusted with the server can read it.
 */
final readonly class CertifyMail
{
    public function __construct(private CertificationLedger $ledger) {}

    /**
     * @param  string  $address  a recipient the caller already had — never one
     *                           a request supplied, or this becomes a mailer
     *                           anybody with an account can point anywhere
     */
    public function send(string $address): MailCertificationOutcome
    {
        $address = trim($address);

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return MailCertificationOutcome::NoRecipient;
        }

        $mailer = (string) config('mail.default', 'log');
        $transport = (string) config(sprintf('mail.mailers.%s.transport', $mailer), $mailer);

        // A log or array mailer is a real configuration that delivers nothing.
        // Reported as itself, so nobody goes looking for a broken relay.
        if (in_array($transport, ['log', 'array'], true)) {
            return MailCertificationOutcome::NotConfigured;
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
            return MailCertificationOutcome::Refused;
        }

        // Fingerprinted, so that changing the relay quietly returns the
        // standing to uncertified rather than leaving a reassuring timestamp
        // over a configuration nobody proved.
        $this->ledger->record('mail', self::fingerprint());

        return MailCertificationOutcome::Accepted;
    }

    /**
     * The configuration this certification would be *about*.
     *
     * Static because it is a pure function of configuration, and because
     * {@see ProviderStandings} has to ask the same question when nobody is
     * sending anything. It used to live on the console command, which meant a
     * read model reached into a command to answer it — the wrong direction,
     * and the reason the standing could not be computed without the console
     * being loaded.
     */
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
