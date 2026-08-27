<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * reservation_id は t_reservations を参照するが、t_reservations も
     * 本テーブルを free_ticket_id で参照する相互参照のため、外部キー制約は
     * t_reservations 作成後の別マイグレーションで追加する。
     */
    public function up(): void
    {
        Schema::create('t_free_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('code')->unique();
            $table->dateTime('issued_at');
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_free_tickets');
    }
};
