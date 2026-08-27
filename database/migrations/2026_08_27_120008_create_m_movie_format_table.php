<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_movie_format', function (Blueprint $table) {
            $table->id();
            $table->foreignId('movie_id')->constrained('m_movies')->cascadeOnDelete();
            $table->foreignId('format_id')->constrained('m_formats')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['movie_id', 'format_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_movie_format');
    }
};
