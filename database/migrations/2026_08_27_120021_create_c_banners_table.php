<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('c_banners', function (Blueprint $table) {
            $table->id();
            $table->string('position');
            $table->string('image_path');
            $table->string('link_url')->nullable();
            $table->string('alt');
            $table->unsignedInteger('sort_order')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('cinema_id')->nullable()->constrained('m_cinemas')->restrictOnDelete();
            $table->timestamps();

            $table->index(['position', 'cinema_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('c_banners');
    }
};
