<?php

declare(strict_types=1);

namespace SaniTube\MusicGeneration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * What a provider's terms said on a given day, as recorded by a person.
 *
 * @property int $id
 * @property string $uuid
 * @property string $provider
 * @property string|null $plan
 * @property string|null $terms_version
 * @property Carbon|null $effective_date
 * @property bool|null $commercial_use_allowed
 * @property string|null $ownership_notes
 * @property string|null $source_reference
 * @property Carbon $captured_at
 */
final class ProviderTermsSnapshot extends Model
{
    use HasPublicUuid;

    protected $table = 'provider_terms_snapshots';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'captured_at' => 'datetime',
            'commercial_use_allowed' => 'boolean',
        ];
    }
}
