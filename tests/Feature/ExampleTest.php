<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The root of the application.
 *
 * SaniTube has no public face — it is a label's own catalogue behind a login —
 * so the correct answer at `/` for a stranger is the sign-in page, not a
 * landing page. This replaces the framework's stock "returns 200" assertion,
 * which was checking a welcome view the platform no longer has.
 */
final class ExampleTest extends TestCase
{
    #[Test]
    public function the_root_sends_a_stranger_to_sign_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }
}
