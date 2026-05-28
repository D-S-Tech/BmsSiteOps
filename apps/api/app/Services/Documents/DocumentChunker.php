<?php

declare(strict_types=1);

namespace App\Services\Documents;

/**
 * Split a document's full text into searchable chunks.
 *
 * Strategy (in order of preference):
 *   1. Phase 1: break the text into "blocks" on paragraph boundaries
 *      (double newline). Any paragraph that is itself larger than
 *      $maxChars is hard-split into smaller pieces with a sliding-window
 *      $overlapChars overlap so a sentence straddling the boundary still
 *      lives intact in at least one chunk.
 *   2. Phase 2: pack blocks into chunks, joining adjacent blocks with
 *      "\n\n" as long as the result stays under $maxChars. A block that
 *      doesn't fit triggers a new chunk.
 *
 * The function is pure — no DB, no time, no I/O. Heavily unit-tested.
 *
 * Defaults: 1500 chars (~250-400 tokens depending on language), 100 char
 * overlap. Operators can tune via metadata in a future sprint; for now
 * 7.1 uses the defaults everywhere.
 */
class DocumentChunker
{
    /**
     * @return list<string>
     */
    public function chunk(string $text, int $maxChars = 1500, int $overlapChars = 100): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        // --- Phase 1: paragraphs -> blocks, hard-splitting oversized ones ---
        $blocks = [];
        $paragraphs = preg_split('/\n\s*\n/u', $text);
        if ($paragraphs === false) {
            $paragraphs = [$text];
        }

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) <= $maxChars) {
                $blocks[] = $paragraph;
            } else {
                array_push($blocks, ...$this->hardSplit($paragraph, $maxChars, $overlapChars));
            }
        }

        if ($blocks === []) {
            return [];
        }

        // --- Phase 2: pack blocks into chunks ---
        $chunks = [];
        $current = '';
        foreach ($blocks as $block) {
            if ($current === '') {
                $current = $block;

                continue;
            }
            $candidate = $current."\n\n".$block;
            if (mb_strlen($candidate) <= $maxChars) {
                $current = $candidate;
            } else {
                $chunks[] = $current;
                $current = $block;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Slide a $maxChars window over $text with $overlapChars of overlap.
     *
     * @return list<string>
     */
    private function hardSplit(string $text, int $maxChars, int $overlapChars): array
    {
        $result = [];
        $total = mb_strlen($text);
        $stride = max(1, $maxChars - max(0, $overlapChars));

        $offset = 0;
        while ($offset < $total) {
            $piece = mb_substr($text, $offset, $maxChars);
            if ($piece === '') {
                break;
            }
            $result[] = $piece;
            // Reached the tail — last piece always shorter than maxChars.
            if (mb_strlen($piece) < $maxChars) {
                break;
            }
            $offset += $stride;
        }

        return $result;
    }
}
