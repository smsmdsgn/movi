<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_seat_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedInteger('surcharge')->default(0);
            $table->string('display_class');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_seat_types');
    }
};
