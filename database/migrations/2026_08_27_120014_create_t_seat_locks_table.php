<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_seat_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('screening_id')->constrained('t_screenings')->cascadeOnDelete();
            $table->foreignId('seat_id')->constrained('m_seats')->cascadeOnDelete();
            $table->string('holder_key');
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['screening_id', 'seat_id']);
            $table->index('holder_key');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_seat_locks');
    }
};
