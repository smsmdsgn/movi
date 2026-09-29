<?php

use App\Models\Admin;
use App\Models\Banner;
use App\Models\Booking;
use App\Models\Cinema;
use App\Models\Format;
use App\Models\Movie;
use App\Models\Post;
use App\Models\Reservation;
use App\Models\Screening;
use App\Models\Theater;
use App\Models\TicketType;
use Illuminate\Support\Facades\Gate;

it('無効化された管理者は、どの Policy でも権限を得られない（17.1.2-6）', function (string $model) {
    $admin = createAdmin();

    expect(Gate::forUser($admin)->allows('viewAny', $model))->toBeTrue();

    $admin->update(['is_active' => false]);

    expect(Gate::forUser($admin)->allows('viewAny', $model))->toBeFalse();
})->with([
    Admin::class,
    Banner::class,
    Booking::class,
    Cinema::class,
    Format::class,
    Movie::class,
    Post::class,
    Reservation::class,
    Screening::class,
    Theater::class,
    TicketType::class,
]);

it('無効化された管理者は、管理画面の到達可否（view-admin-screen）でも拒否される', function () {
    $admin = createAdmin();

    expect(Gate::forUser($admin)->allows('view-admin-screen', 'admin.dashboard'))->toBeTrue();

    $admin->update(['is_active' => false]);

    expect(Gate::forUser($admin)->allows('view-admin-screen', 'admin.dashboard'))->toBeFalse();
});
