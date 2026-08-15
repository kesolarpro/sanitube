<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Blade template and the Vite config must name the same entry points.
 *
 * The PHP suite stubs Vite (see Tests\TestCase), which is what lets it run
 * without a frontend toolchain — but a stub renders an empty tag for an entry
 * point that does not exist just as happily as for one that does. Renaming
 * `app.ts` in the config and forgetting the template would then produce a green
 * PHP suite, a green build, and a blank page in the browser.
 *
 * This closes that gap without needing a build: it compares the two lists
 * against each other and against the filesystem.
 */
final class ViteEntrypointTest extends TestCase
{
    #[Test]
    public function the_template_and_the_vite_config_agree_on_the_entry_points(): void
    {
        $inTemplate = $this->entrypointsIn(
            (string) file_get_contents(resource_path('views/app.blade.php')),
            '/@vite\(\s*\[(?P<list>[^\]]*)\]/',
        );

        $inConfig = $this->entrypointsIn(
            (string) file_get_contents(base_path('vite.config.ts')),
            '/input:\s*\[(?P<list>[^\]]*)\]/',
        );

        $this->assertNotSame([], $inTemplate, 'No @vite([...]) call found in app.blade.php.');
        $this->assertSame($inConfig, $inTemplate);
    }

    #[Test]
    public function every_entry_point_is_a_file_that_exists(): void
    {
        $entrypoints = $this->entrypointsIn(
            (string) file_get_contents(base_path('vite.config.ts')),
            '/input:\s*\[(?P<list>[^\]]*)\]/',
        );

        $this->assertNotSame([], $entrypoints);

        foreach ($entrypoints as $entrypoint) {
            $this->assertFileExists(
                base_path($entrypoint),
                sprintf('Vite builds [%s], which is not in the repository.', $entrypoint),
            );
        }
    }

    /**
     * The quoted paths inside the first bracketed list the pattern matches.
     *
     * @return list<string>
     */
    private function entrypointsIn(string $source, string $pattern): array
    {
        if (preg_match($pattern, $source, $matches) !== 1) {
            return [];
        }

        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $matches['list'], $paths);

        $found = $paths[1];
        sort($found);

        return $found;
    }
}
