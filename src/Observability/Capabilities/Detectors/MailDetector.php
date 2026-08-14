<?php

declare(strict_types=1);

namespace SaniTube\Observability\Capabilities\Detectors;

use SaniTube\Observability\Capabilities\Capability;
use SaniTube\Observability\Capabilities\CapabilityDetector;

final readonly class MailDetector implements CapabilityDetector
{
    public function detect(): Capability
    {
        $mailer = (string) config('mail.default', 'log');
        $transport = (string) config(sprintf('mail.mailers.%s.transport', $mailer), $mailer);

        if (in_array($transport, ['log', 'array'], true)) {
            return Capability::degraded(
                key: 'mail',
                label: 'Mail',
                detail: sprintf('Mailer [%s] does not deliver anything.', $mailer),
                remediation: 'Configure MAIL_MAILER (smtp or sendmail) before going live: '
                    .'password resets and job failure notices depend on it.',
            );
        }

        if ($transport === 'smtp' && blank(config(sprintf('mail.mailers.%s.host', $mailer)))) {
            return Capability::unavailable(
                key: 'mail',
                label: 'Mail',
                detail: 'SMTP is selected but no host is configured.',
                remediation: 'Set MAIL_HOST, MAIL_PORT, MAIL_USERNAME and MAIL_PASSWORD in .env.',
            );
        }

        return Capability::available('mail', 'Mail', sprintf('Mailer [%s] via %s.', $mailer, $transport));
    }
}
