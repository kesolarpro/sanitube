<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    |
    | `none` on a fresh install, and that is the correct default rather than a
    | placeholder. Every AI feature in SaniTube is an assistant to a person who
    | could do the job without it, so an installation with no API key must
    | catalogue, import, analyse and release exactly as well as one with a key.
    | It simply reports the assistance as unavailable.
    |
    | **The disabled provider is called `none`, never `null`.** Laravel's
    | `Env::get()` converts the literal string `null` in a `.env` file into PHP
    | `null`, so `SANITUBE_AI_PROVIDER=null` does not mean "the provider named
    | null" — it means no value at all, the `env()` default never applies
    | because the key *is* present, and the manager is asked to resolve an
    | empty name. That is not a hypothetical: it turned every AI test red in
    | CI while passing locally, because CI copies `.env.example` and a local
    | run may not have the key at all.
    |
    | Set to "openai" or "claude" once the matching key is configured.
    |
    */

    'default' => env('SANITUBE_AI_PROVIDER', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Base URLs come from the environment with no default in PHP, exactly as
    | the R2 and B2 endpoints do. The portability guardrail refuses an absolute
    | host anywhere in application code, and it is right to: an installation
    | behind a corporate gateway, or one pointed at a self-hosted
    | OpenAI-compatible endpoint, changes an environment value and nothing
    | else. `.env.example` ships the public endpoints, so a fresh install has
    | them and only the key is missing.
    |
    | A provider with no key *or no endpoint* is not an error. It reports
    | `isAvailable()` false and is skipped.
    |
    */

    'providers' => [

        // The public name is `none`; the internal driver keeps the word `null`
        // because {@see \SaniTube\AI\Providers\NullAiProvider} is a null
        // object and that is what it is. Only the value a human types had to
        // change.
        'none' => [
            'driver' => 'null',
        ],

        'openai' => [
            'driver' => 'openai',
            'base_url' => env('SANITUBE_OPENAI_BASE_URL'),
            'key' => env('SANITUBE_OPENAI_KEY'),
            'model' => env('SANITUBE_OPENAI_MODEL', 'gpt-4o-mini'),
        ],

        'claude' => [
            'driver' => 'claude',
            'base_url' => env('SANITUBE_CLAUDE_BASE_URL'),
            'key' => env('SANITUBE_CLAUDE_KEY'),
            'model' => env('SANITUBE_CLAUDE_MODEL', 'claude-sonnet-4-5'),
            // Pinned rather than tracking latest: the wire format is versioned
            // by this header, and a silently newer one is a response shape the
            // adapter was never written against.
            'version' => env('SANITUBE_CLAUDE_VERSION', '2023-06-01'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | `timeout` bounds one call. Shorter than the storage timeouts on purpose:
    | a model that has not answered in half a minute is holding a queue worker
    | that has other work, and the feature it serves is optional by design.
    |
    | `max_output_tokens` is required by the Claude API and merely sensible for
    | OpenAI. It is a cost ceiling as much as a technical one.
    |
    */

    'timeout' => (int) env('SANITUBE_AI_TIMEOUT', 30),
    'max_output_tokens' => (int) env('SANITUBE_AI_MAX_OUTPUT_TOKENS', 1024),

    /*
    |--------------------------------------------------------------------------
    | Call ceilings
    |--------------------------------------------------------------------------
    |
    | How many calls this installation is willing to make in a rolling window.
    |
    | **Operational safety, and deliberately not finance.** These count calls,
    | because calls are what the platform can observe. There is no price here,
    | no currency, no balance and no invoice, and this is not a step towards
    | any of those -- it answers "should we keep going", which is an operations
    | question.
    |
    | The failure they prevent is specific. A backfill over four thousand
    | masters enqueues four thousand jobs; the backlog ceiling in
    | `config/operations.php` bounds how many sit on the queue at once and
    | bounds nothing about how many reach a vendor over the following week. A
    | queue that drains steadily spends steadily, and the first sign of that is
    | an invoice.
    |
    | Rolling rather than calendar: a calendar month needs a time zone to be
    | meaningful and a reset somebody has to remember, and "the last 24 hours"
    | cannot be gamed by starting a sweep at 23:55.
    |
    | 0 means no ceiling, and is the shipped default -- a platform whose first
    | experience of an AI feature is a refusal is a platform nobody turns the
    | feature on twice. Set these once you know what a suggestion is worth.
    |
    */

    'limits' => [
        'daily' => (int) env('SANITUBE_AI_DAILY_CALLS', 0),
        'weekly' => (int) env('SANITUBE_AI_WEEKLY_CALLS', 0),
        'monthly' => (int) env('SANITUBE_AI_MONTHLY_CALLS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | The circuit breaker
    |--------------------------------------------------------------------------
    |
    | A vendor having an outage answers every request identically and
    | immediately, and a queue worker holding two thousand enrichment jobs will
    | discover that two thousand times -- each one possibly billed, all of them
    | in the minutes before anybody notices.
    |
    | After this many consecutive *attempted* failures for one provider, the
    | platform stops asking it for the cooldown. It reopens by itself: the
    | first call after the cooldown is allowed through, and if it fails the
    | window simply starts again.
    |
    | Per provider, because an outage at one vendor is not a reason to stop
    | asking another. 0 disables the breaker.
    |
    */

    'circuit' => [
        'consecutive_failures' => (int) env('SANITUBE_AI_CIRCUIT_FAILURES', 5),
        'cooldown_minutes' => (int) env('SANITUBE_AI_CIRCUIT_COOLDOWN_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | The invocation ledger
    |--------------------------------------------------------------------------
    |
    | Every call is recorded: provider, model, prompt version, purpose, token
    | counts, duration and outcome. Two reasons, and neither is optional.
    |
    | Auditability — `AiPrompt` carries a `promptVersion` precisely so that a
    | prompt later found to be wrong can be traced to the records it touched.
    | That is only true if the version was written down at the time.
    |
    | Cost — a per-token vendor bill with no local record of what was spent on
    | what is a bill nobody can check.
    |
    | `store_text` is false by default. Prompts and completions are the part
    | most likely to carry catalogue data, and a ledger is not a place to
    | accumulate copies of it. Turn it on to debug a prompt, then turn it off.
    |
    */

    'ledger' => [
        'store_text' => (bool) env('SANITUBE_AI_LEDGER_STORE_TEXT', false),
    ],

];
