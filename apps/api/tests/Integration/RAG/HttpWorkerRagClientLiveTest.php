<?php

declare(strict_types=1);

namespace Tests\Integration\RAG;

use App\Services\RAG\HttpWorkerRagClient;
use Tests\Integration\IntegrationTestCase;

/**
 * Live integration tests for HttpWorkerRagClient against a running worker.
 *
 * The unit suite proves the HMAC signing + request shape via Http::fake().
 * THIS suite proves the wire actually round-trips against the FastAPI
 * /qa/embed and /qa/answer endpoints with their inbound signature
 * verification middleware.
 *
 * Required env: LIVE_TESTS=1, WORKER_URL, WORKER_INTERNAL_KEY.
 *
 * @group integration
 */
class HttpWorkerRagClientLiveTest extends IntegrationTestCase
{
    private HttpWorkerRagClient $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireEnv(['WORKER_URL', 'WORKER_INTERNAL_KEY']);

        $this->client = new HttpWorkerRagClient(
            baseUrl: (string) env('WORKER_URL'),
            internalKey: (string) env('WORKER_INTERNAL_KEY'),
        );
    }

    public function test_embed_query_round_trips_to_live_worker(): void
    {
        $result = $this->client->embedQuery('When does AHU-1 start?');

        $this->assertArrayHasKey('embedding', $result);
        $this->assertArrayHasKey('model', $result);

        $vector = $result['embedding'];
        $this->assertIsArray($vector);
        $this->assertGreaterThan(0, count($vector), 'live worker returned an empty embedding');

        foreach ($vector as $component) {
            $this->assertIsFloat((float) $component);
        }
    }

    public function test_answer_round_trips_to_live_worker(): void
    {
        $result = $this->client->answer(
            'What is a chiller plant typically supplying?',
            [
                [
                    'content' => 'A chiller plant supplies chilled water at 44-45F to fan coils.',
                    'document_title' => 'Chiller basics',
                    'score' => 0.9,
                ],
            ],
        );

        $this->assertArrayHasKey('answer', $result);
        $this->assertNotEmpty($result['answer'], 'live worker returned empty answer');
        $this->assertArrayHasKey('model', $result);
        $this->assertArrayHasKey('metadata', $result);
        $this->assertArrayHasKey('input_tokens', $result['metadata']);
    }
}
