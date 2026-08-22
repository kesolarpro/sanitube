<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Audit\Enums\AuditAction;
use SaniTube\Audit\Models\AuditEvent;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Operations\Services\BackgroundWork;
use Tests\TestCase;

/**
 * The global stop, from the screen somebody opens during an outage.
 *
 * OPS-002. `sanitube:work:pause` has existed and worked since the switch was
 * built, and it was the only way to press it — so the control an operator
 * reaches for **while watching something go wrong** required SSH, at the moment
 * they are least able to go and find a terminal.
 *
 * The worse half was that the state was reported nowhere. An installation
 * somebody paused yesterday looked identical to a healthy one: same queue page,
 * same job list, same green scheduler, and nothing processed. An operator can
 * live without a button; they cannot diagnose a silent halt they have no way to
 * see.
 */
final class BackgroundWorkControlTest extends TestCase
{
    use RefreshDatabase;

    // ----------------------------------------------------------- the state

    #[Test]
    public function the_operations_screen_says_when_nothing_is_being_taken_on(): void
    {
        $operator = $this->administrator();

        $this->app->make(BackgroundWork::class)->pause($operator, 'HOST_PRESSURE');

        $work = $this->actingAs($operator)
            ->get('/system/operations')
            ->viewData('page')['props']['operations']['work'];

        $this->assertTrue($work['paused']);
        $this->assertSame('HOST_PRESSURE', $work['reason']);
        $this->assertNotNull($work['paused_at']);
        $this->assertSame($operator->name, $work['paused_by']);
    }

    #[Test]
    public function a_running_installation_says_so_without_inventing_a_reason(): void
    {
        $work = $this->actingAs($this->administrator())
            ->get('/system/operations')
            ->viewData('page')['props']['operations']['work'];

        $this->assertFalse($work['paused']);
        $this->assertNull($work['reason']);
        $this->assertNull($work['paused_at']);
        $this->assertNull($work['paused_by']);
    }

    #[Test]
    public function the_screen_names_the_person_and_never_their_address(): void
    {
        $operator = $this->administrator('quiet.person@sanitube.test');

        $this->app->make(BackgroundWork::class)->pause($operator, 'MAINTENANCE');

        $work = $this->actingAs($operator)
            ->get('/system/operations')
            ->viewData('page')['props']['operations']['work'];

        // Scoped to this block rather than to the whole page. The shared auth
        // prop carries the *reader's own* address by design, and asserting
        // against the whole response would pass or fail on that instead — a
        // test that is right by accident and breaks for an unrelated reason.
        $this->assertSame($operator->name, $work['paused_by']);
        $this->assertStringNotContainsString('@', (string) json_encode($work));
    }

    // ---------------------------------------------------------- the control

    #[Test]
    public function an_administrator_can_stop_and_start_the_installation(): void
    {
        $operator = $this->administrator();
        $work = $this->app->make(BackgroundWork::class);

        $this->actingAs($operator)
            ->post('/system/operations/pause', ['reason' => 'PROVIDER_TROUBLE'])
            ->assertRedirect();

        $this->assertTrue($work->isPaused());
        $this->assertSame('PROVIDER_TROUBLE', $work->state()->reason);

        $this->actingAs($operator)->post('/system/operations/resume')->assertRedirect();

        $this->assertFalse($work->isPaused());
    }

    #[Test]
    public function the_emergency_control_works_without_choosing_anything(): void
    {
        // A required reason would make the switch somebody reaches for in a
        // hurry need a decision before it works.
        $this->actingAs($this->administrator())
            ->post('/system/operations/pause')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('OPERATOR_REQUEST', $this->app->make(BackgroundWork::class)->state()->reason);
    }

    #[Test]
    public function the_reason_is_a_closed_vocabulary_and_never_a_sentence(): void
    {
        // Free text here would be a sentence in one language shown to
        // everybody, sitting in an audit record and on a shared screen forever
        // — and the field somebody eventually pastes a customer's name into.
        $this->actingAs($this->administrator())
            ->post('/system/operations/pause', ['reason' => 'the provider keeps 500ing on us'])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($this->app->make(BackgroundWork::class)->isPaused());
    }

    #[Test]
    public function pausing_names_a_person_in_the_audit_log(): void
    {
        $operator = $this->administrator();

        $this->actingAs($operator)->post('/system/operations/pause', ['reason' => 'MAINTENANCE']);
        $this->actingAs($operator)->post('/system/operations/resume');

        $paused = AuditEvent::query()->where('action', AuditAction::BackgroundWorkPaused->value)->firstOrFail();

        $this->assertSame('MAINTENANCE', $paused->reason);
        $this->assertSame($operator->id, $paused->actor_id);

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', AuditAction::BackgroundWorkResumed->value)->count(),
        );
    }

    #[Test]
    public function pressing_it_twice_keeps_the_moment_it_actually_started(): void
    {
        $operator = $this->administrator();
        $work = $this->app->make(BackgroundWork::class);

        $this->actingAs($operator)->post('/system/operations/pause', ['reason' => 'HOST_PRESSURE']);
        $first = $work->state()->paused_at;

        $this->actingAs($operator)->post('/system/operations/pause', ['reason' => 'MAINTENANCE']);

        // The useful question is when this started. A second press of the same
        // button must not reset the answer, and must not rewrite why.
        $this->assertEquals($first, $work->state()->paused_at);
        $this->assertSame('HOST_PRESSURE', $work->state()->reason);
    }

    // ---------------------------------------------------- who may press it

    #[Test]
    public function somebody_who_may_not_administer_cannot_stop_the_platform(): void
    {
        $member = User::factory()->create(['role' => UserRole::Member, 'is_active' => true]);

        $this->actingAs($member)->post('/system/operations/pause')->assertForbidden();
        $this->actingAs($member)->post('/system/operations/resume')->assertForbidden();

        $this->assertFalse($this->app->make(BackgroundWork::class)->isPaused());
    }

    #[Test]
    public function a_stranger_cannot_stop_the_platform(): void
    {
        $this->post('/system/operations/pause')->assertRedirect('/login');

        $this->assertFalse($this->app->make(BackgroundWork::class)->isPaused());
    }

    private function administrator(string $email = 'operator@sanitube.test'): User
    {
        return User::factory()->create([
            'role' => UserRole::Admin,
            'email' => $email,
            'is_active' => true,
        ]);
    }
}
