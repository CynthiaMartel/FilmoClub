<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('category', ['spam', 'harassment', 'inappropriate_content', 'impersonation', 'other']);
            $table->string('reason', 500)->nullable();
            // low_confidence = reporter tiene historial alto de denuncias falsas
            $table->enum('status', ['pending', 'reviewed', 'dismissed', 'actioned'])->default('pending');
            $table->boolean('low_confidence')->default(false);
            $table->string('admin_note', 500)->nullable();
            $table->timestamps();

            // Un usuario no puede denunciar al mismo usuario más de una vez al día (se gestiona en el controller)
            $table->index(['reporter_id', 'created_at']);
            $table->index(['reported_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_reports');
    }
};
