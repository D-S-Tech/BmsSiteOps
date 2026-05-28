<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            // Operator muting: suppress a noisy device from actionable views.
            // Muted indefinitely when muted_until is null; muted_until in the
            // future means a timed mute that auto-expires.
            $table->boolean('is_muted')->default(false)->after('status');
            $table->timestamp('muted_until')->nullable()->after('is_muted');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['is_muted', 'muted_until']);
        });
    }
};
