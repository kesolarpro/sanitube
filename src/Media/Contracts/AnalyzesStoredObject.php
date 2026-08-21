<?php

declare(strict_types=1);

namespace SaniTube\Media\Contracts;

use SaniTube\Media\AudioAnalysisResult;
use SaniTube\Media\Services\AnalyzeAsset;

/**
 * An analyser that can work from a storage key instead of a local file.
 *
 * {@see AudioAnalyzer::analyze()} takes a path on this machine, which is right
 * for a local tool and wrong for a remote one: Core would have to stream the
 * object down to its own disk only for a worker to stream it back up. On a
 * cPanel account talking to R2 that is twice the egress for no benefit.
 *
 * So an analyser that can be handed a key says so, and
 * {@see AnalyzeAsset} skips the temporary file
 * entirely. The same capability-interface pattern the generation contract uses:
 * an implementation opts in, and callers test for it rather than every
 * implementation having to answer a question most of them cannot.
 */
interface AnalyzesStoredObject
{
    /**
     * @param  string  $storageKey  a key in the storage this installation shares
     *                              with its worker
     */
    public function analyzeStored(string $storageKey): AudioAnalysisResult;
}
