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

    #[Test]
    public function the_stylesheet_is_not_built_from_a_runtime_cache(): void
    {
        // `@source '../../storage/framework/views/*.php'` made the production
        // stylesheet depend on whether the application had happened to render
        // anything before `npm run build` ran: a fresh clone produced 34.7 kB
        // and a warmed machine 50.8 kB from the same commit, the difference
        // being compiled vendor Blade the interface never renders.
        //
        // The size was not the problem. An asset pipeline that reads a runtime
        // cache is one whose output nobody can reproduce — CI builds one
        // stylesheet, a developer builds another, and neither is wrong.
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        // Anchored to the start of a line: the file explains in a comment
        // which source was removed and why, and a scanner that read prose as
        // configuration would fail on its own documentation.
        preg_match_all("/^@source\\s+'([^']+)'/m", $css, $matches);

        $this->assertNotEmpty($matches[1], 'The stylesheet declares no sources at all.');

        foreach ($matches[1] as $source) {
            $this->assertStringNotContainsString(
                'storage/',
                $source,
                sprintf('[%s] is a runtime directory. The build must read the repository.', $source),
            );
            $this->assertStringNotContainsString(
                'bootstrap/cache',
                $source,
                sprintf('[%s] is a runtime directory. The build must read the repository.', $source),
            );
        }
    }

    #[Test]
    public function every_blade_view_the_application_ships_is_scanned(): void
    {
        // Module views live beside their module rather than in `resources/`,
        // and the sign-in page is one of them — the single screen a person
        // sees before the Vue application loads at all. A glob rooted at
        // `resources/` does not reach it, and the classes it uses would be
        // absent from the stylesheet on any machine with a cold view cache.
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertStringContainsString(
            "@source '../../src/**/*.blade.php';",
            $css,
            'Module Blade views are not scanned, so the sign-in page can lose its styling silently.',
        );
    }
}
