<?php

declare(strict_types=1);

namespace Tests\Feature\Foundation;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ADR index lists the decisions that exist.
 *
 * `docs/adr/README.md` is the table somebody reads to find out what has already
 * been decided, and it had stopped at 0017 — ADR-0018 and ADR-0019 were written
 * and never listed. A decision nobody can find is a decision that gets taken
 * again, differently, which is the one thing the folder exists to prevent.
 *
 * Both directions, because both fail silently. A file with no row is invisible;
 * a row with no file is a link that 404s in whatever renders the folder, and
 * reads as a decision that was made and then lost.
 *
 * Derived from the directory rather than from a list kept beside it. A hand-kept
 * list of ADRs would be a second index with exactly this failure mode.
 */
final class ArchitectureDecisionIndexTest extends TestCase
{
    #[Test]
    public function every_decision_on_disk_is_listed_in_the_index(): void
    {
        $index = $this->index();
        $files = $this->decisions();

        $this->assertGreaterThan(15, count($files), 'The folder was read as nearly empty, so it was not read.');

        foreach ($files as $file) {
            $this->assertStringContainsString(
                '('.$file.')',
                $index,
                sprintf('[%s] is a decision nobody can find from the index. A decision nobody can find gets taken again.', $file),
            );
        }
    }

    #[Test]
    public function every_row_in_the_index_points_at_a_decision_that_exists(): void
    {
        preg_match_all('/\((ADR-\d{4}[^)]*\.md)\)/', $this->index(), $found);

        $linked = $found[1];

        $this->assertGreaterThan(15, count($linked), 'The index was read as nearly empty, so it was not read.');

        foreach ($linked as $file) {
            $this->assertFileExists(
                base_path('docs/adr/'.$file),
                sprintf('The index links [%s], which is not there. It reads as a decision that was made and lost.', $file),
            );
        }
    }

    private function index(): string
    {
        $path = base_path('docs/adr/README.md');

        if (! is_file($path)) {
            $this->fail('docs/adr/README.md is missing. It is how anybody finds a decision.');
        }

        return (string) file_get_contents($path);
    }

    /**
     * @return list<string>
     */
    private function decisions(): array
    {
        /** @var list<string> $paths */
        $paths = glob(base_path('docs/adr/ADR-*.md')) ?: [];

        return array_map('basename', $paths);
    }
}
