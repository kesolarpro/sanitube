<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Root
|--------------------------------------------------------------------------
|
| Deliberately empty.
|
| SaniTube has no public face: every screen is behind a session. `/` is the
| dashboard, and it is registered by the Ui module alongside the rest of the
| interface — see src/Ui/Routes/web.php — so that the whole application sits
| behind one middleware stack declared in one place.
|
| Until UI-002 this file held a redirect, because the dashboard did not exist
| yet and pointing at an unbuilt page would have been worse than pointing at
| the showcase. Now that it does exist, a second route on `/` would be a
| duplicate whose winner depends on provider registration order, so the
| redirect is gone rather than merely retargeted. A guest is sent to sign in by
| the `auth` middleware, which is where that decision belongs.
|
*/
