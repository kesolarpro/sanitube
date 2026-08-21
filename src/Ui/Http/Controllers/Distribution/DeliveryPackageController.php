<?php

declare(strict_types=1);

namespace SaniTube\Ui\Http\Controllers\Distribution;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use SaniTube\Distribution\Export\BuildDeliveryPackage;
use SaniTube\Distribution\Export\DeliveryPackageKeys;
use SaniTube\Foundation\Contracts\CarriesRefusalCode;
use SaniTube\Releases\Models\Release;
use SaniTube\Storage\StorageManager;
use SaniTube\Ui\Queries\DeliveryPackageQuery;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Delivering by hand, from a browser.
 *
 * DIST-004 built the package and gave it a command. That is the right tool for
 * the initial catalogue and the wrong one for the deployment this platform
 * exists to support: a label on a shared cPanel account has no shell, and a
 * delivery path that requires SSH is a delivery path that installation does not
 * have.
 *
 * **Nothing is submitted here.** Building a package writes two files and no
 * delivery row; it is repeatable and undoable, and the screen says so in as
 * many words. Recording that a distributor received something is a separate,
 * deliberate act — one this screen deliberately does not offer, because a
 * button beside "download" would turn "I have the files" into "they have them".
 *
 * The masters are not served from here. Each is fetched through the ordinary
 * minting endpoint, one at a time, on request — the same hardened path every
 * other screen uses, with the same policy, the same throttle and the same audit
 * line. A screen that arrived carrying twelve signed URLs would have created
 * twelve credentials for somebody who opened it to read a filename.
 */
final class DeliveryPackageController
{
    public function show(Release $release, DeliveryPackageQuery $packages): Response
    {
        return Inertia::render('Distribution/Package', [
            'package' => $packages->for($release),
        ]);
    }

    public function build(Release $release, BuildDeliveryPackage $builder): RedirectResponse
    {
        try {
            $builder->handle($release);
        } catch (CarriesRefusalCode $exception) {
            // The interface, not a module's exception class: packaging refuses
            // in Releases' vocabulary and delivery in Distribution's, and a
            // controller that listed the modules it happened to know about
            // would stop catching the day a third one refused.
            //
            // A code, never the domain's English. The interface holds the
            // sentence in six languages.
            return back()->withErrors(['distribution' => $exception->refusalCode()]);
        }

        return to_route('distribution.package', $release)->with('status', 'distribution.package_built');
    }

    /**
     * The metadata sheet, as a file.
     *
     * Streamed rather than read whole. It is a small file today and a
     * three-hundred-track compilation is not, and a download that has to fit
     * in a shared host's memory limit is a download that fails on the release
     * that mattered.
     */
    public function metadata(
        Release $release,
        StorageManager $storage,
        DeliveryPackageKeys $keys,
    ): StreamedResponse|RedirectResponse {
        $key = $keys->metadata($release);

        try {
            // No `exists()` first. `StorageProvider::readStream()` already
            // guarantees a throw when there is nothing to read — it checks the
            // resource itself rather than trusting the disk's `throw` setting,
            // which differs between the disks this platform ships. A second
            // check would be a second thing to keep true, answering the same
            // refusal.
            $stream = $storage->default()->readStream($key);
        } catch (Throwable) {
            return back()->withErrors(['distribution' => 'PACKAGE_NOT_BUILT']);
        }

        return response()->stream(
            function () use ($stream): void {
                while (! feof($stream)) {
                    echo fread($stream, 8192);
                }

                fclose($stream);
            },
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',

                // The release's uuid, not its title. A filename built from
                // user text is a filename that can carry a quote, a newline
                // and a second header — and a download is exactly where that
                // would be believed.
                'Content-Disposition' => sprintf('attachment; filename="sanitube-%s-metadata.csv"', $release->uuid),

                // A delivery sheet is the catalogue in a file. Nothing between
                // here and the operator may write it down.
                'Cache-Control' => 'private, no-store, max-age=0',
            ],
        );
    }
}
