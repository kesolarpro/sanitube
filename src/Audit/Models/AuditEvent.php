<?php

declare(strict_types=1);

namespace SaniTube\Audit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Enums\AuditActorKind;
use SaniTube\Audit\Enums\AuditOutcome;
use SaniTube\Audit\Enums\AuditSubject;
use SaniTube\Audit\Exceptions\AuditEventIsImmutable;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * One thing that happened. Append-only, and enforced rather than intended.
 *
 * "Nothing updates audit rows" is a property of today's code. An audit log has
 * to be a property of the schema and the model, because the code that would
 * quietly rewrite one is exactly the code somebody adds in a hurry. So
 * `updating` and `deleting` throw, and the retention prune — the one
 * legitimate removal — deletes through the query builder, which is a
 * deliberately different act from calling `delete()` on a record.
 *
 * The model is not the writer. Everything goes through
 * {@see RecordAuditEvent}, which is where the actor is
 * resolved and the context is scrubbed. Constructing one of these by hand
 * skips both.
 *
 * @property int $id
 * @property string $uuid
 * @property AuditAction $action
 * @property AuditSubject $subject
 * @property string|null $subject_uuid
 * @property AuditOutcome $outcome
 * @property string|null $reason
 * @property AuditActorKind $actor_kind
 * @property int|null $actor_id
 * @property string|null $actor_label
 * @property string|null $actor_role
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $context
 * @property Carbon $occurred_at
 */
final class AuditEvent extends Model
{
    use HasPublicUuid;

    protected $table = 'audit_events';

    protected $guarded = [];

    /**
     * There is no `updated_at`: nothing updates.
     */
    public $timestamps = false;

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw AuditEventIsImmutable::cannotBeChanged();
        });

        self::deleting(function (): never {
            // The retention prune removes rows through the query builder,
            // which does not fire this. That is the point: pruning is a
            // different, audited, deliberate act — not something a caller
            // reaches by holding a record.
            throw AuditEventIsImmutable::cannotBeDeleted();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'subject' => AuditSubject::class,
            'outcome' => AuditOutcome::class,
            'actor_kind' => AuditActorKind::class,
            'context' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * The person, when there was one and they still exist.
     *
     * Reading a line does not need this — `actor_label` is the snapshot taken
     * at write time and is what the screen renders. The relation is here for
     * the cases that genuinely want the live record.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
