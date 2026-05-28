<?php

declare(strict_types=1);

namespace Tests\Unit\Documents;

use App\Services\Documents\DocumentChunker;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive unit tests for DocumentChunker.
 *
 * The chunker is pure (no DB, no time, no I/O), so we use plain PHPUnit\TestCase
 * and never touch the database — same posture as the TriageRuleMatcher tests.
 */
class DocumentChunkerTest extends TestCase
{
    private DocumentChunker $chunker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chunker = new DocumentChunker;
    }

    public function test_empty_text_returns_no_chunks(): void
    {
        $this->assertSame([], $this->chunker->chunk(''));
        $this->assertSame([], $this->chunker->chunk("   \n\n  "));
    }

    public function test_short_text_is_one_chunk(): void
    {
        $text = 'Hello world.';
        $this->assertSame(['Hello world.'], $this->chunker->chunk($text));
    }

    public function test_short_text_with_leading_and_trailing_whitespace_is_trimmed(): void
    {
        $this->assertSame(['Hello world.'], $this->chunker->chunk("\n\n  Hello world.  \n\n"));
    }

    public function test_paragraph_packing_keeps_small_paragraphs_together(): void
    {
        $text = "Para one.\n\nPara two.\n\nPara three.";
        $chunks = $this->chunker->chunk($text, maxChars: 100);

        // All three paragraphs fit comfortably under 100 chars, so they pack
        // into a single chunk joined by "\n\n".
        $this->assertCount(1, $chunks);
        $this->assertSame("Para one.\n\nPara two.\n\nPara three.", $chunks[0]);
    }

    public function test_paragraph_packing_flushes_when_next_paragraph_overflows(): void
    {
        // Two ~30-char paragraphs fit (30 + 2 + 30 = 62 chars) under 70, but a third
        // 30-char paragraph would push over -> new chunk.
        $p = str_repeat('a', 30);
        $text = "$p\n\n$p\n\n$p";
        $chunks = $this->chunker->chunk($text, maxChars: 70);

        $this->assertCount(2, $chunks);
        $this->assertSame("$p\n\n$p", $chunks[0]);
        $this->assertSame($p, $chunks[1]);
    }

    public function test_oversized_paragraph_is_hard_split_with_overlap(): void
    {
        $text = str_repeat('A', 1200);
        $chunks = $this->chunker->chunk($text, maxChars: 500, overlapChars: 100);

        // First chunk: chars 0..499 (500 chars)
        // Second chunk: chars 400..899 (500 chars)
        // Third chunk: chars 800..1199 (400 chars)
        $this->assertCount(3, $chunks);
        $this->assertSame(500, mb_strlen($chunks[0]));
        $this->assertSame(500, mb_strlen($chunks[1]));
        $this->assertSame(400, mb_strlen($chunks[2]));

        // Overlap: last 100 chars of chunk 0 == first 100 chars of chunk 1.
        $this->assertSame(
            mb_substr($chunks[0], -100),
            mb_substr($chunks[1], 0, 100)
        );
    }

    public function test_hard_split_respects_zero_overlap(): void
    {
        $text = str_repeat('X', 1000);
        $chunks = $this->chunker->chunk($text, maxChars: 400, overlapChars: 0);

        // 400 + 400 + 200, no overlap
        $this->assertCount(3, $chunks);
        $this->assertSame(400, mb_strlen($chunks[0]));
        $this->assertSame(400, mb_strlen($chunks[1]));
        $this->assertSame(200, mb_strlen($chunks[2]));
    }

    public function test_unicode_text_is_split_on_characters_not_bytes(): void
    {
        // "ć" is 2 bytes in UTF-8. We split on characters, so a 600-char chunk
        // shouldn't be 600 bytes long.
        $text = str_repeat('ć', 600);
        $chunks = $this->chunker->chunk($text, maxChars: 200, overlapChars: 20);

        foreach ($chunks as $c) {
            $this->assertLessThanOrEqual(200, mb_strlen($c), 'chunk too long in characters');
            $this->assertGreaterThan(mb_strlen($c), strlen($c), 'expected multibyte chars');
        }
    }

    public function test_mixed_short_paragraphs_and_one_giant_paragraph(): void
    {
        $giant = str_repeat('B', 1800);
        $text = "Intro paragraph.\n\n$giant\n\nOutro paragraph.";
        $chunks = $this->chunker->chunk($text, maxChars: 800, overlapChars: 50);

        // The giant paragraph hard-splits into multiple chunks. The intro
        // paragraph is alone in chunk 0 (the next block is the 1800-char piece
        // which can't pack with it). The outro paragraph DOES fit at the tail
        // of the last (short) hard-split piece, so the last chunk ends with it.
        $this->assertGreaterThan(2, count($chunks));
        $this->assertSame('Intro paragraph.', $chunks[0]);
        $this->assertStringEndsWith('Outro paragraph.', end($chunks));
    }

    public function test_blank_paragraphs_are_skipped(): void
    {
        // Triple-newlines and stray whitespace shouldn't produce empty chunks.
        $text = "First.\n\n\n\n  \n\nSecond.";
        $chunks = $this->chunker->chunk($text, maxChars: 50);

        $this->assertCount(1, $chunks);
        $this->assertSame("First.\n\nSecond.", $chunks[0]);
    }

    public function test_single_paragraph_exactly_at_max_is_one_chunk_no_overlap(): void
    {
        $text = str_repeat('Z', 500);
        $chunks = $this->chunker->chunk($text, maxChars: 500);

        $this->assertSame([$text], $chunks);
    }
}
