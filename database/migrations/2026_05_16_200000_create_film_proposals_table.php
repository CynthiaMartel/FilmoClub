<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('film_proposals', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('tmdb_id');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->json('tmdb_snapshot');
            $table->text('admin_notes')->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('tmdb_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('film_proposals');
    }
};
