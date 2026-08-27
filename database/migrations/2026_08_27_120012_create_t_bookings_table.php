<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cinema_id')->constrained('m_cinemas')->restrictOnDelete();
            $table->foreignId('movie_id')->constrained('m_movies')->restrictOnDelete();
            $table->foreignId('format_id')->constrained('m_formats')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedInteger('surcharge');
            $table->timestamps();

            $table->index(['cinema_id', 'movie_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_bookings');
    }
};
