<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Catalog\Models\Track;
use SaniTube\Identity\Enums\UserRole;
use SaniTube\Ingestion\Jobs\ProcessIngestionItemJob;
use SaniTube\Ingestion\Models\IngestionBatch;
use SaniTube\Storage\StorageManager;
use SaniTube\Storage\Testing\InMemoryStorageProvider;
use Tests\TestCase;

/**
 * Starting an import without a terminal.
 *
 * `sanitube:import` remains the right tool for the initial catalogue — nine
 * hundred files are a manifest and a long afternoon, and a browser tab is the
 * wrong place to keep either. What was missing is the ordinary case: a label
 * that dropped twenty new masters in a folder and had to be given SSH to
 * bring them in.
 *
 * The claims:
 *
 *   - **Nothing is imported inside the request.** The batch is created and the
 *     work is queued, so closing the tab does not half-run an import.
 *   - **The refusals are the domain's**, reported as codes rather than as the
 *     English a log wants.
 *   - **A MEMBER cannot start one**, whatever the screen shows them.
 */
final class StartImportTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryStorageProvider $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new InMemoryStorageProvider('memory');
        config(['storage.default' => 'memory']);
        $this->app->make(StorageManager::class)->register('memory', $this->store);

        // The test environment runs the queue inline, which would do the whole
        // import inside the request that started it — the one shape ING-001 is
        // designed never to have, and the shape this test exists to refuse.
        Queue::fake();
    }

    #[Test]
    public function a_folder_becomes_a_queued_batch(): void
    {
        $this->store->put('library/01.wav', 'one');
        $this->store->put('library/02.wav', 'two');

        $this->actingAs($this->user())
            ->post('/ingestion/batches', ['prefix' => 'library'])
            ->assertRedirect();

        $batch = IngestionBatch::query()->firstOrFail();

        $this->assertSame(2, $batch->total());
        $this->assertSame(0, Track::query()->count());

        // Queued, not done. The whole point: an import that depends on a tab
        // staying open is an import that half-runs.
        Queue::assertPushed(ProcessIngestionItemJob::class, 2);
    }

    #[Test]
    public function a_list_of_files_becomes_a_queued_batch(): void
    {
        $this->store->put('library/01.wav', 'one');

        $this->actingAs($this->user())
            ->post('/ingestion/batches', ['references' => ['library/01.wav']])
            ->assertRedirect();

        $this->assertSame(1, IngestionBatch::query()->firstOrFail()->total());
    }

    #[Test]
    public function the_batch_records_who_started_it(): void
    {
        // An import is where several hundred things enter the platform at
        // once, and "who started this" is the first question asked when one of
        // them turns out to be wrong.
        $this->store->put('library/01.wav', 'one');

        $operator = $this->user();

        $this->actingAs($operator)
            ->post('/ingestion/batches', ['prefix' => 'library'])
            ->assertRedirect();

        $this->assertSame($operator->getKey(), IngestionBatch::query()->firstOrFail()->created_by);
    }

    #[Test]
    public function importing_nothing_in_particular_is_refused(): void
    {
        // "Importing an entire store blindly is never what someone meant, and
        // it is how a platform ingests its own output." The rule is the
        // domain's; the form does not restate it.
        $this->actingAs($this->user())
            ->post('/ingestion/batches', [])
            ->assertSessionHasErrors(['import' => 'NOTHING_SELECTED']);

        $this->assertSame(0, IngestionBatch::query()->count());
    }

    #[Test]
    public function a_refusal_arrives_as_a_code_and_not_as_a_sentence(): void
    {
        $this->actingAs($this->user())
            ->post('/ingestion/batches', [])
            ->assertSessionHasErrors('import');

        /** @var array<string, mixed> $errors */
        $errors = session('errors')?->getBag('default')->get('import') ?? [];

        foreach ($errors as $error) {
            // A screen that rendered the domain's English would be a screen in
            // one language on a platform that ships six.
            $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]+$/', (string) $error);
        }
    }

    #[Test]
    public function importing_from_the_platforms_own_storage_is_refused(): void
    {
        // The prefix SaniTube writes its own assets to. Importing from it
        // would take the platform's output back in as though it were new
        // material, and each round would produce another copy.
        $this->actingAs($this->user())
            ->post('/ingestion/batches', ['prefix' => $this->managedPrefix()])
            ->assertSessionHasErrors(['import' => 'MANAGED_PREFIX']);
    }

    #[Test]
    public function a_folder_and_a_list_together_are_refused(): void
    {
        $this->store->put('library/01.wav', 'one');
        $this->store->put('elsewhere/02.wav', 'two');

        // The domain kept the folder and dropped the list silently until
        // ING-002 put the two fields on one screen. Two things naming the
        // batch with no rule for which wins is how an import quietly does
        // something nobody asked for — and the person who typed the list would
        // never learn it had been ignored.
        $this->actingAs($this->user())
            ->post('/ingestion/batches', [
                'prefix' => 'library',
                'references' => ['elsewhere/02.wav'],
            ])
            ->assertSessionHasErrors(['import' => 'AMBIGUOUS_SELECTION']);

        $this->assertSame(0, IngestionBatch::query()->count());
    }

    // ------------------------------------------------------- authorisation

    #[Test]
    public function a_member_may_watch_imports_and_start_none(): void
    {
        $this->store->put('library/01.wav', 'one');

        $member = $this->user(UserRole::Member, 'member@example.test');

        $this->actingAs($member)->get('/ingestion/batches')->assertOk();

        // Posted past the interface. The form is absent for a MEMBER, and an
        // absent form is presentation — the guard is the route.
        $this->actingAs($member)
            ->post('/ingestion/batches', ['prefix' => 'library'])
            ->assertForbidden();

        $this->assertSame(0, IngestionBatch::query()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function the_screen_offers_a_member_no_form(): void
    {
        $this->actingAs($this->user(UserRole::Member, 'member@example.test'))
            ->get('/ingestion/batches')
            ->assertInertia(fn ($page) => $page->where('actions.may_start', false));
    }

    #[Test]
    public function the_screen_offers_an_owner_the_form(): void
    {
        $this->actingAs($this->user())
            ->get('/ingestion/batches')
            ->assertInertia(fn ($page) => $page->where('actions.may_start', true));
    }

    // ------------------------------------------------------------ fixtures

    /**
     * A prefix the platform writes its own assets under.
     *
     * Asked of `AssetObjectKeys` rather than written down here: the list is
     * derived from the asset kinds, so a hardcoded copy would go stale the
     * moment a kind is added and the test would stop covering the case it
     * names.
     */
    private function managedPrefix(): string
    {
        $prefixes = $this->app->make(AssetObjectKeys::class)->managedPrefixes();

        return $prefixes[0] ?? 'staging';
    }

    private function user(UserRole $role = UserRole::Owner, string $email = 'owner@example.test'): User
    {
        $user = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => 'Owner', 'password' => 'a-long-enough-passphrase'],
        );

        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }
}
