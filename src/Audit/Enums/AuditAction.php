<?php

declare(strict_types=1);

namespace SaniTube\Audit\Enums;

/**
 * Every operation this platform is willing to write down.
 *
 * An enum rather than a free string, for the same reason `SafelyRetryable` is
 * a marker asked of a job's type rather than a flag on a screen: a closed set
 * makes the omissions visible. A new write cannot be audited until somebody
 * has named it here and decided what it is about, which is a much better
 * failure than a log slowly filling with three spellings of the same event.
 *
 * The names are `domain.subject.verb`, past tense, because an audit log
 * records what happened rather than what was asked for. They are stored in the
 * database and read by whatever an installation points at its log, so they are
 * an interface: renaming one rewrites history.
 *
 * **What is missing is deliberate.** There is no case for reading anything.
 * A log that records every page view drowns the six lines a person actually
 * came looking for, and SaniTube's reads are already bounded by role. The one
 * exception is minting an asset preview, which is a read that produces a
 * bearer credential — that is a write in every sense that matters here.
 */
enum AuditAction: string
{
    // ------------------------------------------------------------- identity

    case UserSignedIn = 'identity.user.signed_in';
    case UserSignInFailed = 'identity.user.sign_in_failed';
    case UserSignedOut = 'identity.user.signed_out';
    case UserCreated = 'identity.user.created';

    // No deactivation, reactivation or role change. Not an omission: SaniTube
    // has no path that performs them — accounts are created by
    // `sanitube:user:create` and altered by hand — and declaring an action
    // nothing can record would put three entries in the filter list that no
    // history will ever contain. Whoever builds user administration adds them
    // here, which is the direction this enum is meant to work in.
    case PasswordResetRequested = 'identity.password.reset_requested';
    case PasswordResetCompleted = 'identity.password.reset_completed';

    // ------------------------------------------------------------ catalogue

    case CandidatePromoted = 'catalogue.candidate.promoted';
    case CandidateRejected = 'catalogue.candidate.rejected';
    case TrackUpdated = 'catalogue.track.updated';

    /*
     * CAT-005. An ISRC or a UPC is the number a store, a collecting society and
     * a royalty statement all key on, and assigning one by hand is the one
     * place in the catalogue where a person supplies a value the platform
     * cannot check against anything but a checksum. Who typed it is worth
     * knowing years later, when a report no longer reconciles.
     */
    case IdentifierAssigned = 'catalogue.identifier.assigned';
    case IdentifierRevoked = 'catalogue.identifier.revoked';

    // ------------------------------------------------------------- releases

    case ReleaseCreated = 'release.release.created';
    case ReleaseMarkedReady = 'release.release.marked_ready';
    case ReleaseReopened = 'release.release.reopened';

    // --------------------------------------------------------- distribution

    case DeliverySubmitted = 'distribution.delivery.submitted';
    case DeliveryTakedownRequested = 'distribution.delivery.takedown_requested';
    case DeliveryReferenceDecided = 'distribution.delivery.reference_decided';

    // --------------------------------------------------------------- assets

    case AssetPreviewMinted = 'asset.preview.minted';
    case AssetUploadStarted = 'asset.upload.started';
    case AssetUploadFinalized = 'asset.upload.finalized';

    /**
     * Setting a master aside, and taking it back.
     *
     * Audited because it is the closest thing the platform has to losing a
     * master, even though it loses nothing: the bytes stay, and only a person
     * can do it. An operator asking "where did that file go" needs a name and
     * a time, and the reason code the reviewer chose.
     */
    case AssetTrashed = 'asset.trashed';
    case AssetRestored = 'asset.restored';

    /**
     * Answering a duplicate finding.
     *
     * Both outcomes are recorded, because a rejection is the more interesting
     * one: it is a person saying the platform was wrong, and a run of them is
     * how a mis-set threshold announces itself.
     */
    case DuplicateConfirmed = 'duplicate.confirmed';
    case DuplicateRejected = 'duplicate.rejected';

    /**
     * A person answering something a model proposed.
     *
     * Both outcomes are recorded, and the rejection is the more interesting
     * line: it is a person saying the model was wrong, and a run of them is how
     * a bad prompt version announces itself. Accepting is audited twice by
     * design -- once here, as "somebody accepted a suggestion", and once as a
     * track update by the catalogue service that actually wrote the columns.
     * The two answer different questions and neither implies the other.
     */
    case SuggestionAccepted = 'enrichment.suggestion.accepted';
    case SuggestionRejected = 'enrichment.suggestion.rejected';

    /**
     * The global stop, lifted and lowered.
     *
     * The subject is the installation itself. Audited because it is the one
     * control that changes what the whole platform will and will not do, and
     * because the question after an incident is always when it was pressed and
     * by whom -- including the case where the answer is "a console command, so
     * nobody is recorded", which is itself worth knowing.
     */
    case BackgroundWorkPaused = 'operations.background_work.paused';
    case BackgroundWorkResumed = 'operations.background_work.resumed';
    case IngestionBatchStarted = 'ingestion.batch.started';

    /*
     * BULK-001. Emptying the inbox is the one bulk delete in the application.
     * It touches nothing the catalogue depends on — an imported file already
     * has its own managed copy — but "several hundred objects went away and
     * nobody knows who asked" is not a sentence an operator should ever have
     * to say.
     */
    case IngestionInboxDiscarded = 'ingestion.inbox.discarded';

    // ------------------------------------------------------------ generation

    case GenerationStarted = 'generation.generation.started';
    case GenerationResultSelected = 'generation.result.selected';

    // --------------------------------------------------------------- system

    case SettingsChanged = 'system.settings.changed';
    case FailedJobRetried = 'system.failed_job.retried';
    case FailedJobForgotten = 'system.failed_job.forgotten';
    case BackupCreated = 'system.backup.created';
    case BackupRestored = 'system.backup.restored';
    case AuditLogPruned = 'system.audit.pruned';

    /**
     * What kind of thing this action is about.
     *
     * Declared by the action rather than passed by the caller, so two call
     * sites recording the same action cannot disagree about what the subject
     * uuid refers to.
     */
    public function subject(): AuditSubject
    {
        return match ($this) {
            self::UserSignedIn,
            self::UserSignInFailed,
            self::UserSignedOut,
            self::UserCreated,
            self::PasswordResetRequested,
            self::PasswordResetCompleted => AuditSubject::User,

            self::CandidatePromoted,
            self::CandidateRejected => AuditSubject::TrackCandidate,

            self::TrackUpdated => AuditSubject::Track,

            self::IdentifierAssigned,
            self::IdentifierRevoked => AuditSubject::ExternalIdentifier,

            self::ReleaseCreated,
            self::ReleaseMarkedReady,
            self::ReleaseReopened => AuditSubject::Release,

            self::DeliverySubmitted,
            self::DeliveryTakedownRequested,
            self::DeliveryReferenceDecided => AuditSubject::Delivery,

            self::AssetPreviewMinted,
            self::AssetUploadStarted,
            self::AssetUploadFinalized,
            self::AssetTrashed,
            self::AssetRestored => AuditSubject::Asset,

            self::DuplicateConfirmed,
            self::DuplicateRejected => AuditSubject::DuplicateRelation,
            self::SuggestionAccepted,
            self::SuggestionRejected => AuditSubject::MetadataSuggestion,

            self::BackgroundWorkPaused,
            self::BackgroundWorkResumed => AuditSubject::System,
            self::IngestionBatchStarted => AuditSubject::IngestionBatch,

            // No batch, and no row at all: the subject is the installation's
            // own inbox. `System` is the honest answer rather than a missing
            // one, which is why the column is not simply nullable.
            self::IngestionInboxDiscarded => AuditSubject::System,

            self::GenerationStarted,
            self::GenerationResultSelected => AuditSubject::Generation,

            self::FailedJobRetried,
            self::FailedJobForgotten => AuditSubject::FailedJob,

            self::SettingsChanged,
            self::BackupCreated,
            self::BackupRestored,
            self::AuditLogPruned => AuditSubject::System,
        };
    }

    /**
     * Whether this is one of the lines somebody investigating an incident
     * reads first.
     *
     * Used only to order and highlight — never to decide what gets recorded.
     * An audit log that keeps the interesting events and drops the boring ones
     * has already decided what the incident was.
     */
    public function isSecuritySignificant(): bool
    {
        return match ($this) {
            self::UserSignInFailed,
            self::UserCreated,
            self::PasswordResetRequested,
            self::PasswordResetCompleted,
            self::AssetPreviewMinted,
            self::SettingsChanged,
            self::BackupRestored,
            self::AuditLogPruned => true,

            default => false,
        };
    }
}
