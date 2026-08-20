<?php

declare(strict_types=1);

namespace SaniTube\Production\Enums;

/**
 * How much of a production plan the platform is allowed to carry out on its
 * own.
 *
 * **A property of the plan, never of an artist.** An artist is a public credit;
 * how much a machine may do unattended is a decision about a body of work, and
 * the same artist can appear on one plan a person drives by hand and another
 * that generates unattended. Putting this on the artist would make those two
 * the same setting.
 *
 * The modes are a ladder, and each rung adds one thing the platform may do
 * without being asked. Read them as "the platform may go this far by itself,
 * and stops".
 */
enum AutonomyMode: string
{
    /**
     * The platform does nothing unasked. Every step is a person pressing
     * something.
     */
    case Manual = 'MANUAL';

    /**
     * The platform prepares and proposes; a person decides. Suggestions get
     * made, inventories get taken, proposals get written — and nothing leaves
     * the review queue without somebody.
     */
    case Assisted = 'ASSISTED';

    /**
     * The platform may generate audio unattended.
     *
     * The first rung that spends money without being asked each time, which is
     * why it is a separate rung from `Assisted` rather than part of it.
     * Generated audio still enters ordinary ingestion and is still a candidate
     * a person promotes.
     */
    case AutonomousGeneration = 'AUTONOMOUS_GENERATION';

    /**
     * The platform may also assemble a release and take it to the point of
     * being ready — artwork, track order, metadata — and stop.
     *
     * Everything up to the last irreversible step.
     */
    case AutonomousPreparation = 'AUTONOMOUS_PREPARATION';

    /**
     * **Locked, and not by configuration.**
     *
     * Handing a release to a distributor is the one operation this platform
     * has that cannot be undone by this platform: shops, stores and aggregators
     * hold the result, takedowns are a request rather than a delete, and an
     * identifier that has been delivered is spent. Doing that without a person
     * is not a feature with a switch missing — it is a decision nobody has
     * made, and the case exists so that the *absence* of the decision is
     * visible in the type rather than implied by there being no code.
     *
     * {@see isAvailable()} returns false for this and there is no setting that
     * changes it. When somebody does decide, they will edit this method, and
     * that edit is a reviewable line in a diff rather than a value in an
     * environment file.
     */
    case AutonomousRelease = 'AUTONOMOUS_RELEASE';

    /**
     * Whether a plan may actually be set to this mode.
     *
     * Deliberately not configuration-driven. A locked mode that an environment
     * variable unlocks is a locked mode in name only, and the whole point of
     * this one is that turning it on should require somebody to change code and
     * have that change read.
     */
    public function isAvailable(): bool
    {
        return $this !== self::AutonomousRelease;
    }

    /**
     * The modes an operator may choose from.
     *
     * @return list<self>
     */
    public static function available(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $mode): bool => $mode->isAvailable(),
        ));
    }

    /**
     * Whether the platform may generate audio under this mode without being
     * asked each time.
     */
    public function mayGenerateUnattended(): bool
    {
        return in_array($this, [self::AutonomousGeneration, self::AutonomousPreparation, self::AutonomousRelease], true);
    }

    /**
     * Whether the platform may assemble a release under this mode.
     *
     * Assembling is not delivering. A release prepared unattended still sits
     * there until a person sends it.
     */
    public function mayPrepareReleases(): bool
    {
        return in_array($this, [self::AutonomousPreparation, self::AutonomousRelease], true);
    }

    /**
     * Whether the platform may hand a release to a distributor unattended.
     *
     * False for every mode that can currently be set, and that is the point.
     */
    public function mayReleaseUnattended(): bool
    {
        return $this === self::AutonomousRelease && $this->isAvailable();
    }
}
