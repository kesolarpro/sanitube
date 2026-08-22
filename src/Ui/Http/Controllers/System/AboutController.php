<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\System;

use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Ui\Queries\SystemAboutQuery;

/**
 * What this installation is, and whether it is well.
 *
 * SYS-001. `sanitube:doctor` has reported nineteen things worth knowing about
 * a live installation since DEP-016 — a queue running inline, a directory that
 * is not writable, an upload ceiling the host will not honour — to a terminal.
 * The operator most likely to need it is the one on shared hosting who chose
 * this platform *because* they did not want a shell, and they could not read a
 * word of it.
 *
 * A read, and only a read. Nothing on this screen changes anything: the
 * remediation for every finding is a sentence, and the settings that would fix
 * most of them have their own screen behind their own rules. A diagnosis page
 * with buttons is a diagnosis page that eventually reconfigures a server from
 * a summary somebody skimmed.
 *
 * Behind `can.role:administer`, with the rest of the system group. It publishes
 * a PHP version, a database server version and a list of migration names —
 * facts about the installation rather than about anybody's data, and none of
 * them a credential.
 */
final class AboutController
{
    public function __invoke(SystemAboutQuery $about): Response
    {
        return Inertia::render('System/About', ['about' => $about->overview()]);
    }
}
