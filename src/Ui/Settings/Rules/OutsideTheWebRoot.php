<?php

declare(strict_types=1);

namespace SaniTube\Ui\Settings\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A path the web server will not serve.
 *
 * CFG-004. A backup holds every row of the catalogue and every
 * credential-shaped value in the database, so one inside the web root is worse
 * than no backup at all — it is the whole database, downloadable, by anybody
 * who guesses the filename.
 *
 * The backup repository already refuses such a destination on every call, and
 * that is the check that matters: it holds however the value got there,
 * including a hand-edited `.env`. This
 * one exists because a form that *accepts* a destination the next backup will
 * refuse is a form that lets somebody save a configuration which then fails at
 * two in the morning. The refusal belongs where the mistake is made as well as
 * where the damage would be done.
 *
 * Compared as normalised strings rather than resolved paths, deliberately: the
 * directory usually does not exist yet — it is created on first use — and
 * `realpath()` on a path that is not there returns false, which would let every
 * new destination through.
 */
final readonly class OutsideTheWebRoot implements ValidationRule
{
    public function __construct(private string $webRoot) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $path = rtrim(trim($value), '/');
        $public = rtrim($this->webRoot, '/');

        if ($path === $public || str_starts_with($path.'/', $public.'/')) {
            $fail('ui.settings.failure.PATH_IS_PUBLIC')->translate();
        }
    }
}
