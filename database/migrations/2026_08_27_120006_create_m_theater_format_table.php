<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_theater_format', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theater_id')->constrained('m_theaters')->cascadeOnDelete();
            $table->foreignId('format_id')->constrained('m_formats')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['theater_id', 'format_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_theater_format');
    }
};
