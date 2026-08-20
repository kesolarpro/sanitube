<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Catalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Catalog\Enums\ExternalIdentifierSource;
use SaniTube\Catalog\Exceptions\ExternalIdentifierException;
use SaniTube\Catalog\ManuallyAssignableIdentifiers;
use SaniTube\Catalog\Models\ExternalIdentifier;
use SaniTube\Catalog\Models\Track;
use SaniTube\Catalog\Services\AssignExternalIdentifier;
use SaniTube\Catalog\Services\RevokeExternalIdentifier;
use SaniTube\Releases\Models\Release;
use SaniTube\Ui\Http\Requests\Catalog\AssignIdentifierRequest;
use SaniTube\Ui\Http\Requests\Catalog\RevokeIdentifierRequest;

/**
 * Putting an identifier a person is holding into the catalogue, and taking one
 * back out.
 *
 * CAT-005. `AssignExternalIdentifier` and `RevokeExternalIdentifier` were built
 * in ARCH-002 and had **no caller anywhere** — not a screen, not an API route,
 * not even a console command. The consequence was not a missing button:
 * REL-004 had just made "no UPC" and "no ISRC" visible as the things that block
 * every delivery, and there was no way in the product to supply either. Telling
 * somebody what is wrong while withholding the fix is worse than saying
 * nothing, because it looks like the platform is working.
 *
 * **Nothing here decides anything the domain decides.** Whether a value is
 * well-formed, whether a second active identifier of a type may exist, and
 * whether a revoked one may come back are ARCH-002's answers, re-asked on every
 * request. This resolves the entity, refuses types a person may not supply,
 * and translates the domain's refusal into a code.
 *
 * **Provenance is the server's, never the request's.** Every assignment made
 * here is MANUAL. A request that could name its own source could record a value
 * as issued by a distributor, and afterwards nothing distinguishes that from
 * one a distributor really issued — the exact problem DIST-001-H1 solved for
 * manual delivery resolution by giving the operator's answer its own
 * provenance.
 *
 * **The identifier is bound, then checked against the entity it was reached
 * through.** Route-model binding on a uuid alone would let anybody with the
 * catalogue role revoke any identifier in the installation by putting its uuid
 * on a release they can see. A 404 rather than a 403: an identifier that is not
 * this release's is not this release's to know about.
 */
final class IdentifierActionController
{
    public function assignToRelease(
        AssignIdentifierRequest $request,
        Release $release,
        AssignExternalIdentifier $assign,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        return $this->assign($request, $release, $assign, $audit);
    }

    public function revokeFromRelease(
        RevokeIdentifierRequest $request,
        Release $release,
        ExternalIdentifier $identifier,
        RevokeExternalIdentifier $revoke,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        return $this->revoke($request, $release, $identifier, $revoke, $audit);
    }

    public function assignToTrack(
        AssignIdentifierRequest $request,
        Track $track,
        AssignExternalIdentifier $assign,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        return $this->assign($request, $track, $assign, $audit);
    }

    public function revokeFromTrack(
        RevokeIdentifierRequest $request,
        Track $track,
        ExternalIdentifier $identifier,
        RevokeExternalIdentifier $revoke,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        return $this->revoke($request, $track, $identifier, $revoke, $audit);
    }

    private function assign(
        AssignIdentifierRequest $request,
        Model $entity,
        AssignExternalIdentifier $assign,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        $about = ['entity' => $entity->getMorphClass(), 'type' => $request->type()];

        // The only place a string from a request becomes a type. A name that is
        // not on this entity's list is refused whatever it is, so a request
        // naming DSP_ID is answered identically to one naming nonsense.
        $type = ManuallyAssignableIdentifiers::resolve($entity, $request->type());

        if ($type === null) {
            $audit->refused(AuditAction::IdentifierAssigned, 'IDENTIFIER_TYPE_NOT_ASSIGNABLE', context: $about);

            return $this->refused('IDENTIFIER_TYPE_NOT_ASSIGNABLE');
        }

        try {
            // Source and authority are named here, positionally after the
            // value, and neither comes from the request.
            $identifier = $assign($entity, $type, $request->value(), ExternalIdentifierSource::Manual);
        } catch (ExternalIdentifierException $exception) {
            $audit->refused(AuditAction::IdentifierAssigned, $exception->reason, context: $about);

            return $this->refused($exception->reason);
        }

        // The type, not the value. An audit row naming the identifier itself
        // would put a number a store keys on into a table nobody scrubs — and
        // the identifier row already holds it, with its own provenance.
        $audit->record(AuditAction::IdentifierAssigned, subjectUuid: $identifier->uuid, context: $about);

        return back()->with('status', 'catalog.identifier_assigned');
    }

    private function revoke(
        RevokeIdentifierRequest $request,
        Model $entity,
        ExternalIdentifier $identifier,
        RevokeExternalIdentifier $revoke,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        if (! $this->belongsTo($identifier, $entity)) {
            abort(404);
        }

        try {
            $revoke($identifier, $request->reason());
        } catch (ExternalIdentifierException $exception) {
            $audit->refused(AuditAction::IdentifierRevoked, $exception->reason, $identifier->uuid);

            return $this->refused($exception->reason);
        }

        // The reason is not copied into the audit context. It is already on the
        // revocation record, which is where somebody reading the identifier's
        // history will look, and free text duplicated into a second table is
        // how an audit log fills with whatever anybody felt like writing.
        $audit->record(AuditAction::IdentifierRevoked, subjectUuid: $identifier->uuid);

        return back()->with('status', 'catalog.identifier_revoked');
    }

    private function belongsTo(ExternalIdentifier $identifier, Model $entity): bool
    {
        return $identifier->identifiable_type === $entity->getMorphClass()
            && (string) $identifier->identifiable_id === (string) $entity->getKey();
    }

    private function refused(string $code): RedirectResponse
    {
        return back()->withErrors(['identifier' => $code]);
    }
}
