<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_theaters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cinema_id')->constrained('m_cinemas')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('name');
            $table->timestamps();

            $table->unique(['cinema_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_theaters');
    }
};
