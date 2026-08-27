<?php

use App\Enums\ReservationStatus;
use Illuminate\Database\UniqueConstraintViolationException;

test('rejects a second paid reservation using the same free ticket', function () {
    [$screening] = createScreeningWithSeat();
    $ticket = createFreeTicket();

    createReservationUsingFreeTicket($screening->id, $ticket->id, ReservationStatus::Paid);

    expect(fn () => createReservationUsingFreeTicket($screening->id, $ticket->id, ReservationStatus::Paid))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('allows a free ticket to be reused after the reservation using it is cancelled', function () {
    [$screening] = createScreeningWithSeat();
    $ticket = createFreeTicket();

    $cancelled = createReservationUsingFreeTicket($screening->id, $ticket->id, ReservationStatus::Paid);
    $cancelled->status = ReservationStatus::Cancelled;
    $cancelled->cancelled_at = now();
    $cancelled->save();

    $reused = createReservationUsingFreeTicket($screening->id, $ticket->id, ReservationStatus::Paid);

    expect($reused->exists)->toBeTrue();
});
