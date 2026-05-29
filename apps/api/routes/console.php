<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sprint 9.2 — nightly retention of MCP audit log. Keeps the table small
// while preserving 90 days of forensic value. Run at 03:15 local time so
// it doesn't fight with backups (Sprint 9.3) which run at 02:00.
Schedule::command('audit:prune-mcp')
    ->dailyAt('03:15')
    ->withoutOverlapping()
    ->onOneServer();
