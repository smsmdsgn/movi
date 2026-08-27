<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_free_tickets', function (Blueprint $table) {
            $table->foreign('reservation_id')->references('id')->on('t_reservations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('t_free_tickets', function (Blueprint $table) {
            $table->dropForeign(['reservation_id']);
        });
    }
};
