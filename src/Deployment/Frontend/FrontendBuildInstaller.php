<?php

declare(strict_types=1);

namespace SaniTube\Deployment\Frontend;

use Illuminate\Support\Carbon;
use SaniTube\Deployment\Exceptions\FrontendBuildException;
use ZipArchive;

/**
 * Puts a CI-built frontend where the server serves it — atomically, for the
 * exact commit it was built from, and never trusting the archive.
 *
 * The production hosts this exists for have no Node: the bundle is built once
 * by CI (`sanitube-frontend-build`, the exact contents of `public/build`)
 * and installed here from a downloaded zip or an unpacked directory.
 *
 * Three rules:
 *
 * **Atomic or nothing.** The build is staged beside the live one and swapped
 * with two renames. At no moment does `public/build` hold half a bundle —
 * a request during install sees the old build or the new one, never a
 * manifest naming files that are still being written.
 *
 * **The archive is hostile until proven boring.** Entries are extracted by
 * streaming each one to a path that is verified to stay inside the staging
 * directory; `../`, absolute names and anything unresolvable is refused with
 * nothing installed. `ZipArchive::extractTo` would have honoured the
 * archive's opinion about where files go.
 *
 * **A build belongs to a commit.** Hashed filenames only match the templates
 * of the code that produced them. When the operator says which commit the
 * artifact came from, it is checked against the checkout's HEAD — read from
 * `.git` as files, because inspection does not execute — and a mismatch is
 * refused with both values named. What was installed, and from which commit,
 * is recorded outside the web root.
 */
final readonly class FrontendBuildInstaller
{
    public function __construct(
        private string $publicPath,
        private string $statePath,
        private string $gitPath,
    ) {}

    /**
     * @return array{entries: int, sha: ?string}
     */
    public function install(string $source, ?string $sha): array
    {
        if (! file_exists($source)) {
            throw FrontendBuildException::sourceMissing($source);
        }

        $head = $this->headCommit();

        if ($sha !== null && $head !== null && ! str_starts_with(strtolower($head), strtolower($sha))) {
            throw FrontendBuildException::commitMismatch($sha, $head);
        }

        $staging = $this->publicPath.'/build.staging-'.bin2hex(random_bytes(4));

        if (! @mkdir($staging, 0755, true)) {
            throw FrontendBuildException::swapFailed('the staging directory could not be created');
        }

        try {
            if (is_dir($source)) {
                $this->copyTree($source, $staging);
            } else {
                $this->extractZip($source, $staging);
            }

            $entries = $this->verifyManifest($staging);
            $this->swap($staging);
        } catch (FrontendBuildException $exception) {
            $this->removeTree($staging);

            throw $exception;
        }

        $this->record($sha ?? $head, $entries);

        return ['entries' => $entries, 'sha' => $sha ?? $head];
    }

    /**
     * HEAD as files: `.git/HEAD`, the ref it names, `packed-refs` as the
     * fallback. Null when this is not a git checkout — a cPanel upload, a
     * release tarball — in which case there is nothing to check against and
     * the operator's `--sha` becomes the record instead of the constraint.
     */
    public function headCommit(): ?string
    {
        $head = @file_get_contents($this->gitPath.'/HEAD');

        if ($head === false) {
            return null;
        }

        $head = trim($head);

        if (preg_match('/^[0-9a-f]{40}$/i', $head) === 1) {
            return strtolower($head);
        }

        if (! str_starts_with($head, 'ref: ')) {
            return null;
        }

        $ref = trim(substr($head, 5));

        $direct = @file_get_contents($this->gitPath.'/'.$ref);

        if ($direct !== false && preg_match('/^[0-9a-f]{40}$/i', trim($direct)) === 1) {
            return strtolower(trim($direct));
        }

        $packed = @file_get_contents($this->gitPath.'/packed-refs');

        if ($packed === false) {
            return null;
        }

        foreach (explode("\n", $packed) as $line) {
            if (preg_match('/^([0-9a-f]{40})\s+'.preg_quote($ref, '/').'$/i', trim($line), $matches) === 1) {
                return strtolower($matches[1]);
            }
        }

        return null;
    }

    private function extractZip(string $archive, string $staging): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw FrontendBuildException::noManifest();
        }

        try {
            $root = realpath($staging);

            if ($root === false) {
                throw FrontendBuildException::swapFailed('the staging directory vanished');
            }

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);

                if ($name === false || $name === '') {
                    throw FrontendBuildException::unsafeArchiveEntry();
                }

                if (str_ends_with($name, '/')) {
                    continue;
                }

                $target = $this->containedPath($root, $name);

                $directory = dirname($target);

                if (! is_dir($directory) && ! @mkdir($directory, 0755, true)) {
                    throw FrontendBuildException::swapFailed('a directory inside the build could not be created');
                }

                // Streamed entry by entry to a path this code chose, never
                // extractTo(): the archive does not get an opinion about
                // destinations, and a symlink entry becomes plain bytes.
                $stream = $zip->getStream((string) $name);

                if ($stream === false) {
                    throw FrontendBuildException::unsafeArchiveEntry();
                }

                $out = @fopen($target, 'wb');

                if ($out === false) {
                    fclose($stream);

                    throw FrontendBuildException::swapFailed('an entry could not be written into staging');
                }

                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * The one place that decides whether an entry name stays inside the
     * staging directory. Purely lexical — the target does not exist yet, so
     * realpath cannot answer — which is exactly why every segment is judged.
     */
    private function containedPath(string $root, string $name): string
    {
        $normalised = str_replace('\\', '/', $name);

        if (str_starts_with($normalised, '/') || preg_match('/^[a-z]:/i', $normalised) === 1) {
            throw FrontendBuildException::unsafeArchiveEntry();
        }

        $segments = [];

        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw FrontendBuildException::unsafeArchiveEntry();
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw FrontendBuildException::unsafeArchiveEntry();
        }

        return $root.'/'.implode('/', $segments);
    }

    private function copyTree(string $from, string $to): void
    {
        $entries = scandir($from);

        if ($entries === false) {
            throw FrontendBuildException::sourceMissing($from);
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $from.'/'.$entry;
            $target = $to.'/'.$entry;

            if (is_link($source)) {
                // A symlink in a build directory points somewhere this bundle
                // does not own. Refusing beats copying whatever it aims at.
                throw FrontendBuildException::unsafeArchiveEntry();
            }

            if (is_dir($source)) {
                if (! @mkdir($target, 0755, true)) {
                    throw FrontendBuildException::swapFailed('a directory inside the build could not be created');
                }

                $this->copyTree($source, $target);

                continue;
            }

            if (! @copy($source, $target)) {
                throw FrontendBuildException::swapFailed('a file inside the build could not be copied');
            }
        }
    }

    private function verifyManifest(string $staging): int
    {
        $raw = @file_get_contents($staging.'/manifest.json');

        if ($raw === false) {
            throw FrontendBuildException::noManifest();
        }

        $manifest = json_decode($raw, true);

        if (! is_array($manifest)) {
            throw FrontendBuildException::noManifest();
        }

        if ($manifest === []) {
            throw FrontendBuildException::emptyManifest();
        }

        return count($manifest);
    }

    private function swap(string $staging): void
    {
        $live = $this->publicPath.'/build';
        $retired = $this->publicPath.'/build.retired-'.bin2hex(random_bytes(4));

        $hadPrevious = is_dir($live);

        if ($hadPrevious && ! @rename($live, $retired)) {
            throw FrontendBuildException::swapFailed('the previous build could not be moved aside');
        }

        if (! @rename($staging, $live)) {
            // Put the old build back before reporting: a failed install must
            // leave the site serving what it was serving.
            if ($hadPrevious) {
                @rename($retired, $live);
            }

            throw FrontendBuildException::swapFailed('the new build could not be moved into place');
        }

        if ($hadPrevious) {
            // Only after the new build is live. This is where stale hashed
            // assets go — with their whole directory, not by guessing which
            // hashes are old.
            $this->removeTree($retired);
        }
    }

    private function record(?string $sha, int $entries): void
    {
        if (! is_dir($this->statePath)) {
            @mkdir($this->statePath, 0755, true);
        }

        @file_put_contents(
            $this->statePath.'/frontend-build.json',
            json_encode([
                'sha' => $sha,
                'entries' => $entries,
                'installed_at' => Carbon::now()->toAtomString(),
            ], JSON_PRETTY_PRINT)."\n",
        );
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;

            if (is_dir($child) && ! is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
