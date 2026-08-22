<?php

declare(strict_types=1);

namespace SaniTube\Observability\Certification;

/**
 * What happened when the platform tried to send one real message.
 *
 * CFG-003. Four words, and the distinctions are the point. **Not configured**
 * is not a fault: a mailer that writes to the log is a real, deliberate
 * development configuration, and calling it broken would send somebody hunting
 * a problem they chose. **Refused** is the transport saying no, which is a
 * credential or a host to fix. **Accepted** is all SMTP can ever promise — the
 * message left; where it landed is DNS.
 */
enum MailCertificationOutcome: string
{
    /** The configured mailer delivers nothing, so there is nothing to prove. */
    case NotConfigured = 'NOT_CONFIGURED';

    /** The transport took the message. */
    case Accepted = 'CONNECTED';

    /** The transport refused it. */
    case Refused = 'FAILED';

    /** There was no address to send to. */
    case NoRecipient = 'NO_RECIPIENT';
}
