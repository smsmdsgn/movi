<?php

use App\Enums\ReservationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * active_free_ticket_id は status が paid のとき free_ticket_id、それ以外は NULL
     * となる生成列。(active_free_ticket_id) のユニーク制約により、キャンセルで
     * used_at が null に戻された無料鑑賞券が別の予約へ二重に適用されることを防ぐ。
     * 座席の released_at / active_seat_id（6.4.2）と同じ考え方である。
     */
    public function up(): void
    {
        Schema::create('t_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reservation_no', 8)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('guest_name_kana')->nullable();
            $table->string('contact_type');
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();
            $table->foreignId('screening_id')->constrained('t_screenings')->restrictOnDelete();
            $table->string('status');
            $table->unsignedInteger('total_amount');
            $table->foreignId('free_ticket_id')->nullable()->constrained('t_free_tickets')->restrictOnDelete();
            $table->string('entry_code', 32)->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->dateTime('refunded_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->unsignedBigInteger('active_free_ticket_id')
                ->nullable()
                ->storedAs("case when status = '".ReservationStatus::Paid->value."' then free_ticket_id else null end");

            $table->unique('active_free_ticket_id');
            $table->index(['screening_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index('guest_email');
            $table->index('guest_phone');
            $table->index('guest_name_kana');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_reservations');
    }
};
