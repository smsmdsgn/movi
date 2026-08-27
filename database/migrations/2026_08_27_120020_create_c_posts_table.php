<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('c_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('m_post_categories')->restrictOnDelete();
            $table->foreignId('cinema_id')->nullable()->constrained('m_cinemas')->restrictOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('m_admins')->restrictOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('status');
            $table->dateTime('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('c_posts');
    }
};
