<?php

use App\Models\Seat;
use App\Models\SeatType;
use Database\Seeders\SeatLayoutGenerator;
use Database\Seeders\SeedConfig;

beforeEach(function () {
    foreach (SeedConfig::SEAT_TYPES as $name => $attributes) {
        SeatType::firstOrCreate(['name' => $name], $attributes);
    }
});

test('a front row is narrower than the theater width, centered by dropping the first and last column', function () {
    $theater = createTheater();

    SeatLayoutGenerator::generate($theater, rows: 10, cols: 13, hasAisle: true, hasExecutive: false);

    $frontRow = Seat::where('theater_id', $theater->id)->where('row_label', 'A')->orderBy('grid_col')->get();

    expect($frontRow)->toHaveCount(11);
    expect($frontRow->first()->grid_col)->toBe(2);
    expect($frontRow->last()->grid_col)->toBe(12);
});

test('a horizontal aisle leaves a gap in grid_row between the front and back sections', function () {
    $theater = createTheater();

    SeatLayoutGenerator::generate($theater, rows: 10, cols: 13, hasAisle: true, hasExecutive: false);

    $gridRows = Seat::where('theater_id', $theater->id)->distinct()->pluck('grid_row')->sort()->values();

    // 10行・横通路あり(frontRowsBeforeAisle=5)のため、row6以降の grid_row が1つずつ後ろにずれる
    expect($gridRows->all())->toBe([1, 2, 3, 4, 5, 7, 8, 9, 10, 11]);
});

test('a scale without an aisle has no gap in grid_row', function () {
    $theater = createTheater();

    SeatLayoutGenerator::generate($theater, rows: 8, cols: 10, hasAisle: false, hasExecutive: false);

    $gridRows = Seat::where('theater_id', $theater->id)->distinct()->pluck('grid_row')->sort()->values();

    expect($gridRows->all())->toBe(range(1, 8));
});

test('a back-section row splits into two blocks around a center aisle', function () {
    $theater = createTheater();

    SeatLayoutGenerator::generate($theater, rows: 10, cols: 13, hasAisle: true, hasExecutive: false);

    // 前方セクションは A(前列)〜E(全幅、frontRowsBeforeAisle=5)。F が横通路直後の最初の分割行
    $row = Seat::where('theater_id', $theater->id)->where('row_label', 'F')->orderBy('grid_col')->get();

    expect($row->pluck('grid_col')->min())->toBe(1);
    expect($row->pluck('grid_col')->max())->toBe(13);
    expect($row->count())->toBeLessThan(13);
    expect($row->pluck('grid_col')->contains(7))->toBeFalse();

    // seat_number は grid_col ではなく行内の位置（左から01始まり）で連番になる
    $sixthSeat = $row->firstWhere('grid_col', 8);
    expect($sixthSeat->seat_number)->toBe('06');
});

test('the last row places wheelchair seats at both ends and stays contiguous', function () {
    $theater = createTheater();

    SeatLayoutGenerator::generate($theater, rows: 10, cols: 13, hasAisle: true, hasExecutive: false);

    $lastRow = Seat::where('theater_id', $theater->id)->where('row_label', 'J')->with('seatType')->orderBy('grid_col')->get();

    expect($lastRow)->toHaveCount(13);
    expect($lastRow->pluck('grid_col')->all())->toBe(range(1, 13));
    expect($lastRow->whereIn('grid_col', [1, 2, 12, 13])->pluck('seatType.name')->unique()->all())->toBe(['車椅子']);
});

test('the last row places executive seats at the center only for scales with executive seating', function () {
    $theater = createTheater();

    SeatLayoutGenerator::generate($theater, rows: 12, cols: 16, hasAisle: true, hasExecutive: true);

    $lastRow = Seat::where('theater_id', $theater->id)->where('row_label', 'L')->with('seatType')->orderBy('grid_col')->get();

    expect($lastRow->where('seatType.name', 'エグゼクティブ'))->toHaveCount(8);
    expect($lastRow->where('seatType.name', '車椅子'))->toHaveCount(4);
});

test('the last row places no executive seats for scales without executive seating', function () {
    $theater = createTheater();

    SeatLayoutGenerator::generate($theater, rows: 10, cols: 13, hasAisle: true, hasExecutive: false);

    $lastRow = Seat::where('theater_id', $theater->id)->where('row_label', 'J')->with('seatType')->get();

    expect($lastRow->where('seatType.name', 'エグゼクティブ'))->toHaveCount(0);
});

test('re-generating a theater that already has seats does not duplicate them', function () {
    $theater = createTheater();

    SeatLayoutGenerator::generate($theater, rows: 8, cols: 10, hasAisle: false, hasExecutive: false);
    $firstCount = Seat::where('theater_id', $theater->id)->count();

    SeatLayoutGenerator::generate($theater, rows: 8, cols: 10, hasAisle: false, hasExecutive: false);
    $secondCount = Seat::where('theater_id', $theater->id)->count();

    expect($secondCount)->toBe($firstCount);
});
