<?php

use Illuminate\Support\Facades\DB;

test('tests run against a real MariaDB connection', function () {
    expect(DB::connection()->getDriverName())->toBe('mariadb');
});

test('t_reservation_seats.active_seat_id still derives from released_at and seat_id', function () {
    $expression = DB::selectOne(
        'select GENERATION_EXPRESSION as expr from information_schema.COLUMNS
         where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ? and COLUMN_NAME = ?',
        ['t_reservation_seats', 'active_seat_id']
    )?->expr;

    expect($expression)->not->toBeNull();
    expect($expression)->toContain('released_at');
    expect($expression)->toContain('seat_id');
});

test('t_reservations.active_free_ticket_id still derives from the paid status literal', function () {
    $expression = DB::selectOne(
        'select GENERATION_EXPRESSION as expr from information_schema.COLUMNS
         where TABLE_SCHEMA = DATABASE() and TABLE_NAME = ? and COLUMN_NAME = ?',
        ['t_reservations', 'active_free_ticket_id']
    )?->expr;

    expect($expression)->not->toBeNull();
    expect($expression)->toContain('paid');
    expect($expression)->toContain('free_ticket_id');
});
