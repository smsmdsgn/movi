<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_cinemas', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('concept');
            $table->string('address');
            $table->string('phone');
            $table->string('business_hours');
            $table->text('facility_info');
            $table->text('access_note');
            $table->string('map_embed_url');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_cinemas');
    }
};
