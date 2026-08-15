<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use SaniTube\Foundation\Concerns\HasPublicUuid;
use SaniTube\Identity\Enums\UserRole;

/**
 * A person who uses this installation.
 *
 * Left in `App\Models` rather than moved into `src/Identity`: `config/auth.php`
 * points at it, Laravel's password-reset and remember-me plumbing resolves it,
 * and a move would be a rename with no behavioural benefit. The *rules* about
 * what a user may do live in {@see UserRole}, which is where the domain is.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $email
 * @property UserRole $role
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasPublicUuid;
    use Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /*
     * `role` and `is_active` are deliberately NOT fillable.
     *
     * Mass assignment is how a request body becomes a column, and a request
     * body that can set `role` is a privilege escalation waiting for the first
     * endpoint that forwards user input into `create()`. There is no
     * registration form in SaniTube, so nothing legitimate needs them fillable
     * — the console command and the installer set them with forceFill, which
     * is explicit and greppable.
     */

    /**
     * Route binding resolves on the uuid, so no internal id — and therefore no
     * hint at how many accounts exist — ever appears in a URL.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'role' => UserRole::class,
            // Hashing is the cast's job, so a plaintext password cannot be
            // written by forgetting to call Hash::make somewhere.
            'password' => 'hashed',
        ];
    }
}
