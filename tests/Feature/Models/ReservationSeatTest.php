<?php

use App\Models\ReservationSeat;
use Illuminate\Database\UniqueConstraintViolationException;

test('rejects a duplicate active seat for the same screening', function () {
    [$screening, $seat] = createScreeningWithSeat();
    $ticketType = createTicketType();

    createReservationSeat($screening->id, $seat->id, $ticketType->id);

    expect(fn () => createReservationSeat($screening->id, $seat->id, $ticketType->id))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('allows the same seat to be reserved independently for a different screening in the same theater', function () {
    [$screening, $seat] = createScreeningWithSeat();
    $otherScreening = createScreeningForTheater($screening->theater);
    $ticketType = createTicketType();

    createReservationSeat($screening->id, $seat->id, $ticketType->id);
    $forOtherScreening = createReservationSeat($otherScreening->id, $seat->id, $ticketType->id);

    expect($forOtherScreening->exists)->toBeTrue();
});

test('allows resale of a seat once the previous reservation seat is released', function () {
    [$screening, $seat] = createScreeningWithSeat();
    $ticketType = createTicketType();

    $cancelled = createReservationSeat($screening->id, $seat->id, $ticketType->id);
    $cancelled->released_at = now();
    $cancelled->save();

    $resold = createReservationSeat($screening->id, $seat->id, $ticketType->id);

    expect($resold->exists)->toBeTrue();
    expect($cancelled->fresh()->active_seat_id)->toBeNull();
    expect($resold->fresh()->active_seat_id)->toBe($seat->id);
});

test('keeps every released row when a seat is resold and released repeatedly', function () {
    [$screening, $seat] = createScreeningWithSeat();
    $ticketType = createTicketType();

    $first = createReservationSeat($screening->id, $seat->id, $ticketType->id);
    $first->released_at = now();
    $first->save();

    $second = createReservationSeat($screening->id, $seat->id, $ticketType->id);
    $second->released_at = now();
    $second->save();

    $third = createReservationSeat($screening->id, $seat->id, $ticketType->id);

    expect($third->exists)->toBeTrue();
    expect(
        ReservationSeat::where('screening_id', $screening->id)
            ->where('seat_id', $seat->id)
            ->whereNotNull('released_at')
            ->count()
    )->toBe(2);
});
