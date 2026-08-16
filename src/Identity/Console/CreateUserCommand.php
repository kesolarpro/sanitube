<?php

declare(strict_types=1);

namespace SaniTube\Identity\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Services\RecordAuditEvent;
use SaniTube\Identity\Enums\UserRole;

/**
 * Creates the first account, and every one after it.
 *
 * There is no self-registration in SaniTube and there should not be: this is a
 * label's own catalogue, not a service anyone signs up for. An open
 * registration form on an installation holding unreleased masters is a way in,
 * not a feature.
 *
 * The password is prompted for **secretly** and never echoed, never passed as
 * an argument, and never written to the command history — which is what
 * `--password` would do, since shell history and process listings both record
 * arguments.
 */
final class CreateUserCommand extends Command
{
    protected $signature = 'sanitube:user:create
                            {--name= : The person\'s display name}
                            {--email= : Their email address}
                            {--role=MEMBER : OWNER, ADMIN or MEMBER}';

    protected $description = 'Create a SaniTube user account';

    public function handle(RecordAuditEvent $audit): int
    {
        $name = (string) ($this->option('name') ?? '');
        $email = (string) ($this->option('email') ?? '');

        if ($name === '') {
            $name = (string) $this->ask('Name');
        }

        if ($email === '') {
            $email = (string) $this->ask('Email');
        }

        $role = UserRole::tryFrom(strtoupper((string) $this->option('role')));

        if ($role === null) {
            $this->error('Role must be OWNER, ADMIN or MEMBER.');

            return self::INVALID;
        }

        // secret(), never ask(): a password echoed to a terminal is a password
        // in the scrollback, and on a shared host that scrollback is somebody
        // else's too.
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->error('The passwords do not match.');

            return self::INVALID;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                // Length over composition rules. A twelve-character passphrase
                // is stronger than "P@ss1!" and is one a person will actually
                // remember rather than write on a note.
                'password' => ['required', 'string', 'min:12'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            // Hashed by the model cast, so no plaintext can reach the column
            // by somebody forgetting a Hash::make here.
            'password' => $password,
        ]);

        // forceFill, because `role` is not mass-assignable: a request body
        // that can set a role is a privilege escalation waiting for the first
        // endpoint that forwards user input into create(). Setting it here is
        // explicit and greppable.
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        // Console, so the actor is the system: nobody is authenticated at a
        // terminal. The role goes in the context because "who was made an
        // OWNER, and when" is the first question asked after an incident.
        $audit->record(AuditAction::UserCreated, subjectUuid: $user->uuid, context: ['role' => $role->value]);

        $this->info(sprintf('Created %s (%s) as %s.', $user->name, $user->email, $role->value));

        return self::SUCCESS;
    }
}
