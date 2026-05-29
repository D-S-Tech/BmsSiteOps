<?php

declare(strict_types=1);

namespace Tests\Integration;

use Tests\TestCase;

/**
 * Base class for live integration tests.
 *
 * Every concrete test extends this and gets the LIVE_TESTS guard for free.
 * The class is also marked @group integration so PHPUnit's CLI default
 * exclusion in phpunit.xml catches it even if a developer forgets to set
 * the env var.
 *
 * @group integration
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLive();
    }

    /**
     * Skip the test if LIVE_TESTS=1 isn't in the environment. Honored both
     * via real env var and Laravel config (`config('app.live_tests')`) so
     * test runners that fence env vars still see it via .env.testing.
     */
    protected function skipUnlessLive(): void
    {
        if (env('LIVE_TESTS') !== '1' && getenv('LIVE_TESTS') !== '1') {
            $this->markTestSkipped('LIVE_TESTS=1 not set — live integration tests skipped.');
        }
    }

    /**
     * Skip the test if any of the listed env vars is missing. Use to keep
     * a missing API URL from turning into a confusing connection error.
     *
     * @param  list<string>  $names
     */
    protected function requireEnv(array $names): void
    {
        $missing = [];
        foreach ($names as $name) {
            if (env($name) === null && getenv($name) === false) {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            $this->markTestSkipped('missing env var(s): '.implode(', ', $missing));
        }
    }
}
