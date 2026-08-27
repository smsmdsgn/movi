<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_movies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('tmdb_id')->unique();
            $table->string('title');
            $table->string('original_title')->nullable();
            $table->text('synopsis');
            $table->string('poster_path')->nullable();
            $table->unsignedInteger('runtime_minutes');
            $table->date('released_on');
            $table->json('genres')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_movies');
    }
};
