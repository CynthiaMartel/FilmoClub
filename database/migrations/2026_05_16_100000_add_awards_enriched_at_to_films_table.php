<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('films', function (Blueprint $table) {
            // NULL = nunca procesado, timestamp = última vez que se consultó Wikidata para awards
            $table->timestamp('awards_enriched_at')->nullable()->after('total_festivals');
        });
    }

    public function down(): void
    {
        Schema::table('films', function (Blueprint $table) {
            $table->dropColumn('awards_enriched_at');
        });
    }
};
