<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * free_ticket_id が null の行数を集計してスタンプ数を求める（カウンタ列を持たない）。
     * 無料鑑賞券への交換時は行を削除せず free_ticket_id を設定して履歴として残す（4.5.1 / 6.2）。
     */
    public function up(): void
    {
        Schema::create('t_stamps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reservation_id')->unique()->constrained('t_reservations')->restrictOnDelete();
            $table->foreignId('free_ticket_id')->nullable()->constrained('t_free_tickets')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_stamps');
    }
};
