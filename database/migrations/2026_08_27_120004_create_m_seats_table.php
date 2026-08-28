<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('theater_id')->constrained('m_theaters')->restrictOnDelete();
            $table->foreignId('seat_type_id')->constrained('m_seat_types')->restrictOnDelete();
            $table->string('row_label');
            $table->string('seat_number');
            $table->unsignedInteger('grid_row');
            $table->unsignedInteger('grid_col');
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['theater_id', 'row_label', 'seat_number']);
            $table->unique(['theater_id', 'grid_row', 'grid_col']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_seats');
    }
};
