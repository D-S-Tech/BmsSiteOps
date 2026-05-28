<?php

declare(strict_types=1);

namespace Tests\Unit\RAG;

use App\Services\RAG\VectorSearch;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the cosineSimilarity helper. No DB.
 */
class VectorSearchCosineTest extends TestCase
{
    private VectorSearch $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->search = new VectorSearch;
    }

    public function test_identical_vectors_have_similarity_one(): void
    {
        $v = [0.1, 0.2, 0.3, 0.4];
        $this->assertEqualsWithDelta(1.0, $this->search->cosineSimilarity($v, $v), 1e-9);
    }

    public function test_opposite_vectors_have_similarity_negative_one(): void
    {
        $a = [1.0, 2.0, 3.0];
        $b = [-1.0, -2.0, -3.0];
        $this->assertEqualsWithDelta(-1.0, $this->search->cosineSimilarity($a, $b), 1e-9);
    }

    public function test_orthogonal_vectors_have_similarity_zero(): void
    {
        $a = [1.0, 0.0];
        $b = [0.0, 1.0];
        $this->assertEqualsWithDelta(0.0, $this->search->cosineSimilarity($a, $b), 1e-9);
    }

    public function test_zero_vector_returns_zero(): void
    {
        $this->assertSame(0.0, $this->search->cosineSimilarity([0.0, 0.0, 0.0], [1.0, 2.0, 3.0]));
        $this->assertSame(0.0, $this->search->cosineSimilarity([1.0, 2.0, 3.0], [0.0, 0.0, 0.0]));
    }

    public function test_mismatched_lengths_use_common_prefix(): void
    {
        // First two dimensions: a=[1,0], b=[1,0]  -> identical on the prefix.
        $a = [1.0, 0.0, 9.9];
        $b = [1.0, 0.0];
        $this->assertEqualsWithDelta(1.0, $this->search->cosineSimilarity($a, $b), 1e-9);
    }

    public function test_empty_vectors_return_zero(): void
    {
        $this->assertSame(0.0, $this->search->cosineSimilarity([], []));
        $this->assertSame(0.0, $this->search->cosineSimilarity([], [1.0]));
    }
}
