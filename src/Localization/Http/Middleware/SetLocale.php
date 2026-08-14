<?php

declare(strict_types=1);

namespace SaniTube\Localization\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SaniTube\Localization\LocaleNegotiator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves and applies the interface locale for the current request.
 *
 * An explicit `?lang=` choice is remembered in the session so the preference
 * survives navigation without ever appearing in generated URLs.
 */
final readonly class SetLocale
{
    public function __construct(private LocaleNegotiator $negotiator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $queryParameter = (string) config('localization.negotiation.query_parameter', 'lang');
        $sessionKey = (string) config('localization.negotiation.session_key', 'sanitube.locale');

        $explicit = $request->query($queryParameter);
        $stored = $request->hasSession() ? $request->session()->get($sessionKey) : null;

        $locale = $this->negotiator->negotiate(
            explicit: is_string($explicit) ? $explicit : null,
            stored: is_string($stored) ? $stored : null,
            acceptLanguage: $request->headers->get('Accept-Language'),
        );

        app()->setLocale($locale->code);

        if (is_string($explicit) && $request->hasSession()) {
            $request->session()->put($sessionKey, $locale->code);
        }

        return $next($request);
    }
}
