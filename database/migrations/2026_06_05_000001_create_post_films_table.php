<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_films', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('post_id');
            $table->unsignedInteger('film_id');
            $table->unsignedTinyInteger('order')->default(0);
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('post')->onDelete('cascade');
            $table->foreign('film_id')->references('idFilm')->on('films')->onDelete('cascade');
            $table->unique(['post_id', 'film_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_films');
    }
};
