<?php

namespace App\Providers;

use App\Services\RAG\HttpWorkerRagClient;
use App\Services\RAG\WorkerRagClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // RAG worker transport — singleton so we share keep-alive over the
        // synchronous Q&A pipeline. Production uses HttpWorkerRagClient;
        // tests swap in FakeWorkerRagClient via $this->app->instance(...).
        $this->app->singleton(WorkerRagClient::class, function ($app) {
            return new HttpWorkerRagClient(
                baseUrl: (string) config('services.worker.url', 'http://localhost:8001'),
                internalKey: (string) config('services.worker.internal_key', ''),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
