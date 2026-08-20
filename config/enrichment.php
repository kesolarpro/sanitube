<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Automatic enrichment
    |--------------------------------------------------------------------------
    |
    | Off, and more emphatically than transcription's equivalent.
    |
    | This is the *second* paid call on the same asset: the first was the
    | transcription that produced the evidence this one reads. An installation
    | that switched both on together has doubled a bill before seeing a single
    | result, and nothing else in the platform would stop it -- OPS-002 guards
    | the depth of the queue, not an external invoice.
    |
    | So this is a decision an operator makes after reading a few suggestions
    | and deciding they are worth the money. Until then, a person asks.
    |
    */

    'automatic' => (bool) env('SANITUBE_ENRICHMENT_AUTOMATIC', false),

    /*
    |--------------------------------------------------------------------------
    | How much evidence is sent
    |--------------------------------------------------------------------------
    |
    | A nine-minute transcript is a large payload on a per-token bill, and the
    | first few thousand characters already carry the language, the subject and
    | the register -- which is all this task asks about.
    |
    | It is also a payload ceiling in the security sense: a transcript is text
    | that arrived from outside this installation, and an unbounded one is an
    | unbounded request. The truncation is announced to the model rather than
    | silent, so a cut transcript is not read as a recording that simply ends.
    |
    */

    'max_evidence_characters' => (int) env('SANITUBE_ENRICHMENT_MAX_EVIDENCE_CHARACTERS', 8000),

];
