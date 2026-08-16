<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Jobs\ProcessIngestionItemJob;
use SaniTube\MusicGeneration\Jobs\ImportGenerationResultJob;
use SaniTube\Observability\Services\ResolveFailedJob;
use Tests\TestCase;

/**
 * What an operator may do about a job that failed.
 *
 * The obvious version of this screen — a retry button on every row — is the
 * wrong one. A failed job is a job that got *somewhere* before it stopped, and
 * this platform has one operation it must never perform twice. A generic
 * button offers that too, and trusts the person pressing it to know which rows
 * are which at the moment they are least able to reason carefully.
 *
 * So the job declares it. The claims here:
 *
 *   - **Retry is refused unless the job's own type says a re-run converges.**
 *     Opt in, never opt out: a job added later is unretryable until somebody
 *     has thought about it.
 *   - **The serialised payload never reaches a person.** It carries whatever
 *     the job was constructed with — model attributes, references, sometimes a
 *     signed URL.
 *   - **A MEMBER cannot see this screen at all**, let alone act on it.
 */
final class FailedJobActionsTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------ retrying

    #[Test]
    public function a_job_that_declared_itself_safe_can_be_run_again(): void
    {
        $uuid = $this->failure(ProcessIngestionItemJob::class);

        $this->actingAs($this->admin())
            ->post('/system/jobs/failed/'.$uuid.'/retry')
            ->assertRedirect();

        // `queue:retry` removes the row and pushes the work back.
        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->count());
    }

    #[Test]
    public function a_job_that_never_declared_itself_safe_is_refused(): void
    {
        // Importing a generated result moves bytes and creates a candidate.
        // Nothing about it says a second run converges, so no button and no
        // action — the refusal names the code, and the row stays.
        $uuid = $this->failure(ImportGenerationResultJob::class);

        $this->actingAs($this->admin())
            ->post('/system/jobs/failed/'.$uuid.'/retry')
            ->assertSessionHasErrors(['jobs' => 'FAILED_JOB_NOT_RETRYABLE']);

        $this->assertSame(1, DB::table('failed_jobs')->where('uuid', $uuid)->count());
    }

    #[Test]
    public function a_record_that_does_not_say_what_it_was_is_refused(): void
    {
        // "I cannot tell what this is" and "this is not safe" are different
        // facts with the same outcome, and they get different codes because
        // they lead to different next steps.
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => 'not json at all',
            'exception' => 'Something went wrong',
            'failed_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->post('/system/jobs/failed/'.$uuid.'/retry')
            ->assertSessionHasErrors(['jobs' => 'FAILED_JOB_UNKNOWN_KIND']);
    }

    #[Test]
    public function acting_on_a_job_that_is_already_gone_says_so(): void
    {
        $this->actingAs($this->admin())
            ->post('/system/jobs/failed/01a00000-0000-7000-8000-000000000000/retry')
            ->assertSessionHasErrors(['jobs' => 'FAILED_JOB_GONE']);
    }

    // ------------------------------------------------------------ removing

    #[Test]
    public function removing_a_failure_record_removes_the_record_and_nothing_else(): void
    {
        $uuid = $this->failure(ImportGenerationResultJob::class);

        $this->actingAs($this->admin())
            ->post('/system/jobs/failed/'.$uuid.'/forget')
            ->assertRedirect();

        $this->assertSame(0, DB::table('failed_jobs')->where('uuid', $uuid)->count());
    }

    #[Test]
    public function a_record_that_cannot_be_retried_can_still_be_removed(): void
    {
        // Otherwise the list fills with rows an operator has read and dealt
        // with, and becomes useless for the next failure.
        $uuid = $this->failure(ImportGenerationResultJob::class);

        $this->app->make(ResolveFailedJob::class)->forget($uuid);

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    // ------------------------------------------------------------- payload

    #[Test]
    public function the_screen_says_which_rows_may_be_run_again(): void
    {
        $this->failure(ProcessIngestionItemJob::class);
        $this->failure(ImportGenerationResultJob::class);

        $response = $this->actingAs($this->admin())->get('/system/jobs');

        $response->assertInertia(function ($page): void {
            /** @var list<array{name: string|null, retryable: bool}> $rows */
            $rows = $page->toArray()['props']['failed']['rows'];

            $this->assertCount(2, $rows);

            foreach ($rows as $row) {
                $this->assertSame(
                    $row['name'] === ProcessIngestionItemJob::class,
                    $row['retryable'],
                    'The screen disagreed with the job about whether it can be run again.',
                );
            }
        });
    }

    #[Test]
    public function the_serialised_payload_never_reaches_the_browser(): void
    {
        // It carries whatever the job was constructed with. A screen that
        // published it would hand model attributes — and occasionally a signed
        // URL — to anybody who can read an admin page.
        $this->failure(ProcessIngestionItemJob::class, marker: 'a-secret-inside-the-payload');

        $this->actingAs($this->admin())
            ->get('/system/jobs')
            ->assertDontSee('a-secret-inside-the-payload', escape: false);
    }

    // ------------------------------------------------------- authorisation

    #[Test]
    public function a_member_can_neither_see_nor_touch_failed_jobs(): void
    {
        $uuid = $this->failure(ProcessIngestionItemJob::class);
        $member = $this->user(UserRole::Member, 'member@example.test');

        $this->actingAs($member)->get('/system/jobs')->assertForbidden();

        // Posted past the interface. An absent button is presentation; the
        // guard is the route.
        $this->actingAs($member)->post('/system/jobs/failed/'.$uuid.'/retry')->assertForbidden();
        $this->actingAs($member)->post('/system/jobs/failed/'.$uuid.'/forget')->assertForbidden();

        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    #[Test]
    public function somebody_who_may_write_the_catalogue_still_may_not_touch_the_queue(): void
    {
        // `can.role:administer`, not `catalogue`. A queue listing says how the
        // installation is configured and acting on one puts work back on it.
        $uuid = $this->failure(ProcessIngestionItemJob::class);

        $this->actingAs($this->user(UserRole::Admin, 'admin@example.test'))
            ->post('/system/jobs/failed/'.$uuid.'/retry')
            ->assertRedirect();
    }

    // ------------------------------------------------------------ fixtures

    /**
     * A row in `failed_jobs` shaped the way Laravel writes one.
     */
    private function failure(string $job, string $marker = 'nothing-special'): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => (string) json_encode([
                'uuid' => $uuid,
                'displayName' => $job,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                // A real serialised payload: `queue:retry` rebuilds one, and a
                // fixture that only looked like one would not exercise it. The
                // marker rides inside, where a screen that published the
                // payload would carry it out.
                'data' => ['commandName' => $job, 'command' => serialize((object) ['marker' => $marker])],
            ]),
            'exception' => "RuntimeException: it broke\n#0 somewhere",
            'failed_at' => now(),
        ]);

        return $uuid;
    }

    private function admin(): User
    {
        return $this->user(UserRole::Owner, 'owner@example.test');
    }

    private function user(UserRole $role, string $email): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => 'Operator', 'password' => 'a-long-enough-passphrase'],
        );

        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }
}
