<?php

declare(strict_types=1);

namespace Tests\Feature\Ingestion;

use PHPUnit\Framework\Attributes\Test;
use SaniTube\Assets\Storage\AssetObjectKeys;
use SaniTube\Ingestion\Exceptions\ManifestException;
use SaniTube\Ingestion\Manifest\Manifest;
use SaniTube\Ingestion\Manifest\ManifestParser;
use SaniTube\Ingestion\Manifest\ManifestRow;
use SaniTube\Ingestion\Manifest\ManifestRowError;
use SaniTube\Ingestion\Sources\CloudImportReader;
use SaniTube\Storage\StorageManager;
use Tests\TestCase;

/**
 * Reading the spreadsheet somebody exported from wherever their catalogue
 * lived before.
 *
 * Two properties are what these tests exist for. **A bad row is refused, not
 * fatal** — a manifest of nine hundred lines with three mistakes imports eight
 * hundred and ninety seven, or the feature is useless for the job it was built
 * for. And **nothing is silently dropped** — a column nobody recognised, an
 * ISRC that will not parse, a reference named twice: each comes back with its
 * line number rather than quietly not happening.
 */
final class ManifestParserTest extends TestCase
{
    // ------------------------------------------------------------ the file

    #[Test]
    public function it_reads_a_manifest_into_rows_keyed_by_reference(): void
    {
        $manifest = $this->parse(<<<'CSV'
            reference,title,artist,album,track,isrc
            library/01.wav,Opening,A Band,First Light,1,FR-Z03-14-00001
            library/02.wav,Second,A Band,First Light,2,
            CSV);

        $this->assertSame(2, $manifest->acceptedCount());
        $this->assertSame(0, $manifest->refusedCount());
        $this->assertSame(['library/01.wav', 'library/02.wav'], $manifest->references());

        $row = $manifest->rowFor('library/01.wav');

        $this->assertInstanceOf(ManifestRow::class, $row);
        $this->assertSame('Opening', $row->title);
        $this->assertSame('A Band', $row->artist);
        $this->assertSame('First Light', $row->releaseTitle);
        $this->assertSame(1, $row->trackNumber);

        // Normalised by the catalogue's own normaliser, not a second copy of
        // the rules. If these two disagreed, an import would accept what
        // assignment later refuses — after the bytes were already in.
        $this->assertSame('FRZ031400001', $row->isrc);

        $this->assertNull($manifest->rowFor('library/02.wav')?->isrc);
    }

    #[Test]
    public function a_utf8_bom_does_not_swallow_the_first_column(): void
    {
        // Excel writes one. Left in place it becomes part of the first
        // header's name, and the reference column silently stops existing.
        $manifest = $this->parse("\u{FEFF}reference,title\nlibrary/01.wav,Opening\n");

        $this->assertSame(['library/01.wav'], $manifest->references());
    }

    #[Test]
    public function a_semicolon_separated_export_is_read_as_one(): void
    {
        $manifest = $this->parse("reference;title;artist\nlibrary/01.wav;Opening;A Band\n");

        $this->assertSame('Opening', $manifest->rowFor('library/01.wav')?->title);
    }

    #[Test]
    public function header_spellings_are_accepted_as_written(): void
    {
        $manifest = $this->parse(<<<'CSV'
            Object Key,Track Title,Primary Artist,Disc No,Track No
            library/01.wav,Opening,A Band,2,7
            CSV);

        $row = $manifest->rowFor('library/01.wav');

        $this->assertInstanceOf(ManifestRow::class, $row);
        $this->assertSame('Opening', $row->title);
        $this->assertSame('A Band', $row->artist);
        $this->assertSame(2, $row->discNumber);
        $this->assertSame(7, $row->trackNumber);
    }

    #[Test]
    public function a_column_with_no_field_is_kept_and_reported(): void
    {
        $manifest = $this->parse(<<<'CSV'
            reference,title,mood,bpm
            library/01.wav,Opening,wistful,128
            CSV);

        $this->assertSame(['mood', 'bpm'], $manifest->unknownColumns);

        // Kept, not discarded. An operator who put `mood` in their spreadsheet
        // is entitled to have it survive the import and to be told SaniTube
        // has no field for it.
        $this->assertSame(['mood' => 'wistful', 'bpm' => '128'], $manifest->rowFor('library/01.wav')?->extra);
    }

    #[Test]
    public function blank_lines_are_not_rows(): void
    {
        $manifest = $this->parse("reference,title\nlibrary/01.wav,Opening\n\n,\nlibrary/02.wav,Second\n");

        $this->assertSame(2, $manifest->acceptedCount());
        $this->assertSame(0, $manifest->refusedCount());
    }

    // ----------------------------------------------------- refused as a file

    #[Test]
    public function a_manifest_with_no_reference_column_is_refused_whole(): void
    {
        $this->expectException(ManifestException::class);

        $this->parse("title,artist\nOpening,A Band\n");
    }

    #[Test]
    public function a_filename_column_is_not_accepted_as_the_reference(): void
    {
        // A filename is not an identity — everyone's file is called
        // master.wav. Accepting a column of them as the thing to fetch would
        // encourage exactly the mistake the ingestion key exists to prevent.
        $this->expectException(ManifestException::class);

        $this->parse("filename,title\nmaster.wav,Opening\n");
    }

    #[Test]
    public function two_columns_meaning_the_same_field_are_refused(): void
    {
        $this->expectException(ManifestException::class);

        $this->parse("reference,title,song_title\nlibrary/01.wav,One,Two\n");
    }

    #[Test]
    public function a_header_with_no_rows_is_refused(): void
    {
        $this->expectException(ManifestException::class);

        $this->parse("reference,title\n");
    }

    #[Test]
    public function a_manifest_past_the_row_ceiling_is_refused_by_name(): void
    {
        config(['ingestion.manifest_max_rows' => 3]);

        $csv = "reference,title\n";

        for ($i = 1; $i <= 5; $i++) {
            $csv .= sprintf("library/%02d.wav,Track %d\n", $i, $i);
        }

        $this->expectException(ManifestException::class);
        $this->expectExceptionMessageMatches('/more than 3 rows/');

        $this->parse($csv);
    }

    // ------------------------------------------------------ refused as rows

    #[Test]
    public function a_row_naming_nothing_is_refused_and_the_rest_import(): void
    {
        $manifest = $this->parse(<<<'CSV'
            reference,title
            library/01.wav,Opening
            ,Orphan
            library/03.wav,Third
            CSV);

        $this->assertSame(['library/01.wav', 'library/03.wav'], $manifest->references());
        $this->assertSame(1, $manifest->refusedCount());
        $this->assertSame(ManifestRowError::MISSING_REFERENCE, $manifest->errors[0]->code);
        $this->assertSame(3, $manifest->errors[0]->line);
    }

    #[Test]
    public function a_malformed_isrc_refuses_its_row_rather_than_being_dropped(): void
    {
        $manifest = $this->parse(<<<'CSV'
            reference,title,isrc
            library/01.wav,Opening,NOT-AN-ISRC
            library/02.wav,Second,FRZ031400002
            CSV);

        // The bytes would import perfectly well without the identifier, and
        // that is the problem: the operator stated an ISRC, and a platform
        // that silently decided it did not count would leave the recording
        // looking as though none was ever claimed for it.
        $this->assertSame(['library/02.wav'], $manifest->references());
        $this->assertSame(ManifestRowError::MALFORMED_IDENTIFIER, $manifest->errors[0]->code);
        $this->assertSame('library/01.wav', $manifest->errors[0]->reference);
    }

    #[Test]
    public function a_malformed_upc_refuses_its_row(): void
    {
        $manifest = $this->parse("reference,upc\nlibrary/01.wav,12345\n");

        $this->assertTrue($manifest->isEmpty());
        $this->assertSame(ManifestRowError::MALFORMED_IDENTIFIER, $manifest->errors[0]->code);
    }

    #[Test]
    public function a_non_numeric_track_number_refuses_its_row(): void
    {
        $manifest = $this->parse("reference,track\nlibrary/01.wav,A-side\n");

        $this->assertTrue($manifest->isEmpty());
        $this->assertSame(ManifestRowError::MALFORMED_NUMBER, $manifest->errors[0]->code);
    }

    #[Test]
    public function a_reference_named_twice_refuses_both_lines(): void
    {
        $manifest = $this->parse(<<<'CSV'
            reference,title
            library/01.wav,Opening
            library/02.wav,Second
            library/01.wav,Opening (alternate)
            CSV);

        // Both, not just the later one. Two rows describing the same object
        // with different metadata is a question for whoever wrote the
        // manifest, and answering it by taking whichever came first is an
        // arbitrary decision presented as a result.
        $this->assertSame(['library/02.wav'], $manifest->references());
        $this->assertSame(2, $manifest->refusedCount());

        foreach ($manifest->errors as $error) {
            $this->assertSame(ManifestRowError::DUPLICATE_REFERENCE, $error->code);
            $this->assertSame('library/01.wav', $error->reference);
        }
    }

    #[Test]
    public function one_isrc_claimed_by_two_rows_refuses_both(): void
    {
        $manifest = $this->parse(<<<'CSV'
            reference,title,isrc
            library/01.wav,Opening,FRZ031400001
            library/02.wav,Second,FR-Z03-14-00001
            library/03.wav,Third,FRZ031400003
            CSV);

        // Written two different ways, so this only works because normalisation
        // happens before the comparison. One ISRC identifies one recording.
        $this->assertSame(['library/03.wav'], $manifest->references());
        $this->assertSame(2, $manifest->refusedCount());
        $this->assertSame(ManifestRowError::DUPLICATE_ISRC, $manifest->errors[0]->code);
    }

    #[Test]
    public function a_reference_the_source_would_refuse_is_a_row_error_not_a_dead_manifest(): void
    {
        $managed = $this->app->make(AssetObjectKeys::class)->managedPrefixes()[0];

        $manifest = $this->parse(
            "reference,title\n".$managed."/anything.wav,Ours\nlibrary/02.wav,Theirs\n",
            withReader: true,
        );

        $this->assertSame(['library/02.wav'], $manifest->references());
        $this->assertSame(ManifestRowError::UNACCEPTABLE_REFERENCE, $manifest->errors[0]->code);
    }

    // ------------------------------------------------------------ evidence

    #[Test]
    public function evidence_survives_the_round_trip_to_the_item_and_back(): void
    {
        // The worker that imports a file never sees the CSV. It sees what was
        // written to the item, minutes or hours later, possibly after a
        // restart — so the round trip has to be lossless.
        $manifest = $this->parse(<<<'CSV'
            reference,title,artist,album,disc,track,language,isrc,upc,distributor,notes,mood
            library/01.wav,Opening,A Band,First Light,2,7,fr,FRZ031400001,012345678905,OldCo,legacy,wistful
            CSV);

        $original = $manifest->rowFor('library/01.wav');
        $this->assertInstanceOf(ManifestRow::class, $original);

        $restored = ManifestRow::fromEvidence($original->toEvidence());

        $this->assertEquals($original, $restored);
    }

    #[Test]
    public function a_field_nobody_stated_is_absent_rather_than_null(): void
    {
        $manifest = $this->parse("reference,title\nlibrary/01.wav,Opening\n");

        $evidence = $manifest->rowFor('library/01.wav')?->toEvidence() ?? [];

        // "Nobody supplied a language" and "the language was supplied as
        // nothing" are the same fact. Storing an explicit null invites a later
        // reader to treat it as a stated absence.
        $this->assertArrayNotHasKey('language', $evidence);
        $this->assertArrayNotHasKey('isrc', $evidence);
        $this->assertSame('Opening', $evidence['title']);
    }

    // ------------------------------------------------------------- helpers

    private function parse(string $csv, bool $withReader = false): Manifest
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $csv);
        rewind($stream);

        $reader = $withReader
            ? new CloudImportReader(
                $this->app->make(StorageManager::class),
                $this->app->make(AssetObjectKeys::class),
            )
            : null;

        try {
            return $this->app->make(ManifestParser::class)->parse($stream, $reader);
        } finally {
            fclose($stream);
        }
    }
}
