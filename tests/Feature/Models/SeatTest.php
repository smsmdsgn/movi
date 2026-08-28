<?php

use App\Models\Seat;

test('a seat defaults to available', function () {
    [, $seat] = createScreeningWithSeat();

    expect($seat->fresh()->is_available)->toBeTrue();
});

test('the available seat query excludes unavailable seats but keeps available ones', function () {
    [, $seat] = createScreeningWithSeat();

    expect(Seat::available()->find($seat->id))->not->toBeNull();

    $seat->update(['is_available' => false]);

    expect(Seat::available()->find($seat->id))->toBeNull();
    expect(Seat::query()->find($seat->id))->not->toBeNull();
});
