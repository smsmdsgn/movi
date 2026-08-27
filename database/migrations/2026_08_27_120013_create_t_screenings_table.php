<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained('t_bookings')->restrictOnDelete();
            $table->foreignId('theater_id')->constrained('m_theaters')->restrictOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('m_admins')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();

            $table->index(['theater_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_screenings');
    }
};
