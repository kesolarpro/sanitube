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
