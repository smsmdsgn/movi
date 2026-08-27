<?php

use App\Models\SeatLock;
use Illuminate\Database\UniqueConstraintViolationException;

test('rejects a duplicate lock for the same screening and seat', function () {
    [$screening, $seat] = createScreeningWithSeat();

    SeatLock::create([
        'screening_id' => $screening->id,
        'seat_id' => $seat->id,
        'holder_key' => 'session:aaa',
        'expires_at' => now()->addMinutes(10),
    ]);

    expect(fn () => SeatLock::create([
        'screening_id' => $screening->id,
        'seat_id' => $seat->id,
        'holder_key' => 'session:bbb',
        'expires_at' => now()->addMinutes(10),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('allows locking the same seat again once the previous lock is deleted', function () {
    [$screening, $seat] = createScreeningWithSeat();

    $lock = SeatLock::create([
        'screening_id' => $screening->id,
        'seat_id' => $seat->id,
        'holder_key' => 'session:aaa',
        'expires_at' => now()->addMinutes(10),
    ]);
    $lock->delete();

    $newLock = SeatLock::create([
        'screening_id' => $screening->id,
        'seat_id' => $seat->id,
        'holder_key' => 'session:bbb',
        'expires_at' => now()->addMinutes(10),
    ]);

    expect($newLock->exists)->toBeTrue();
});

test('allows the same seat to be locked independently for a different screening in the same theater', function () {
    [$screeningA, $seat] = createScreeningWithSeat();
    $screeningB = createScreeningForTheater($screeningA->theater);

    SeatLock::create([
        'screening_id' => $screeningA->id,
        'seat_id' => $seat->id,
        'holder_key' => 'session:aaa',
        'expires_at' => now()->addMinutes(10),
    ]);

    $lockForOtherScreening = SeatLock::create([
        'screening_id' => $screeningB->id,
        'seat_id' => $seat->id,
        'holder_key' => 'session:bbb',
        'expires_at' => now()->addMinutes(10),
    ]);

    expect($lockForOtherScreening->exists)->toBeTrue();
});
