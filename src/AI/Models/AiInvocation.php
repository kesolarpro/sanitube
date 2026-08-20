<?php

declare(strict_types=1);

namespace SaniTube\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use SaniTube\Foundation\Concerns\HasPublicUuid;

/**
 * One recorded call to a language model.
 *
 * Never updated after it is written. An invocation is something that happened.
 *
 * @property int $id
 * @property string $uuid
 * @property string $provider
 * @property string|null $model
 * @property string $purpose
 * @property string $prompt_version
 * @property bool $succeeded
 * @property bool $attempted
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $duration_ms
 * @property string|null $prompt_text
 * @property string|null $completion_text
 * @property string|null $failure_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AiInvocation extends Model
{
    use HasPublicUuid;

    protected $table = 'ai_invocations';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'attempted' => 'boolean',
        ];
    }
}
