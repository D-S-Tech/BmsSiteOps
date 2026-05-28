<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // Transport for reaching the source. Only meaningful for Niagara
            // sources (obix | rest | fox); null for other kinds. Placed after
            // `kind` for readability.
            $table->string('transport', 16)->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('transport');
        });
    }
};
