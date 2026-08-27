<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * active_seat_id は released_at IS NULL のとき seat_id、それ以外は NULL
     * となる生成列。(screening_id, active_seat_id) のユニーク制約により、
     * 解放済み座席を任意件数残しつつ有効な予約座席のみを一意に制約する（6.4.2）。
     */
    public function up(): void
    {
        Schema::create('t_reservation_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained('t_reservations')->restrictOnDelete();
            $table->foreignId('screening_id')->constrained('t_screenings')->restrictOnDelete();
            $table->foreignId('seat_id')->constrained('m_seats')->restrictOnDelete();
            $table->foreignId('ticket_type_id')->constrained('m_ticket_types')->restrictOnDelete();
            $table->unsignedInteger('amount');
            $table->dateTime('released_at')->nullable();
            $table->unsignedBigInteger('active_seat_id')
                ->nullable()
                ->storedAs('case when released_at is null then seat_id else null end');
            $table->timestamps();

            $table->unique(['screening_id', 'active_seat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_reservation_seats');
    }
};
