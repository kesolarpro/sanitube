<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Ingestion;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Ingestion\Services\InboxDeposit;
use SaniTube\Storage\Exceptions\StorageOperationFailed;
use SaniTube\Storage\StorageManager;

/**
 * Throwing away what is waiting in the inbox.
 *
 * Needed because an import that half-happened leaves objects nobody wants: a
 * deposit that was abandoned, a file picked by mistake, a round uploaded twice
 * because a tab was reopened. Without this the only way to clear them is a
 * bucket console, and on a shared account there is no bucket console.
 *
 * **This deletes nothing the platform has taken responsibility for.** The
 * inbox is where files wait *before* a batch refers to them; once imported,
 * the asset holds its own copy under a managed key and the inbox object is no
 * longer the catalogue's memory of anything. Every key is checked against the
 * inbox prefix before it is deleted, so this can never be pointed at a master.
 */
final class InboxDiscardController
{
    public function __invoke(
        Request $request,
        StorageManager $storage,
        InboxDeposit $inbox,
        RecordAuditEvent $audit,
    ): RedirectResponse {
        $store = $storage->default();
        $prefix = $inbox->prefix().'/';

        try {
            $keys = $store->files($prefix);
        } catch (StorageOperationFailed) {
            return back()->withErrors(['import' => 'STORAGE_UNAVAILABLE']);
        }

        $discarded = 0;

        foreach ($keys as $key) {
            // Belt and braces. `files()` is already scoped to the prefix, and
            // this is the one loop in the application that deletes in bulk —
            // the cost of asking twice is nothing next to the cost of being
            // wrong once.
            if (! str_starts_with($key, $prefix)) {
                continue;
            }

            try {
                $store->delete($key);
                $discarded++;
            } catch (StorageOperationFailed) {
                // One object refusing to go is not a reason to strand the
                // rest. The screen re-reads the inbox and shows what is left.
            }
        }

        $audit->record(AuditAction::IngestionInboxDiscarded, context: ['count' => (string) $discarded]);

        return back()->with('status', 'ingestion.inbox_discarded');
    }
}
