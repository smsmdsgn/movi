<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_admins', function (Blueprint $table) {
            $table->id();
            $table->string('login_id')->unique();
            $table->string('password');
            $table->string('name');
            $table->string('role');
            $table->foreignId('cinema_id')->nullable()->constrained('m_cinemas')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_admins');
    }
};
