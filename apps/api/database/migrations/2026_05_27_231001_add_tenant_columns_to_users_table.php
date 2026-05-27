<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_tenant_id')
                ->nullable()
                ->after('email')
                ->constrained('tenants')
                ->nullOnDelete();
            $table->boolean('is_super_admin')
                ->default(false)
                ->after('current_tenant_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_tenant_id');
            $table->dropColumn('is_super_admin');
        });
    }
};
