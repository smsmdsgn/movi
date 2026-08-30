<?php

use App\Enums\BannerPosition;
use App\Models\Banner;
use Database\Seeders\BannerSeeder;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\SeedConfig;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => MasterDataSeeder::class, '--force' => true]);
    createTheater();
    createTheater();
    createTheater();
});

test('seeding creates the configured number of banners per position', function () {
    Artisan::call('db:seed', ['--class' => BannerSeeder::class, '--force' => true]);

    expect(Banner::count())->toBe(array_sum(SeedConfig::BANNER_COUNTS));

    foreach (SeedConfig::BANNER_COUNTS as $position => $count) {
        expect(Banner::where('position', $position)->count())->toBe($count);
    }
});

test('main banners are not tied to a specific cinema', function () {
    Artisan::call('db:seed', ['--class' => BannerSeeder::class, '--force' => true]);

    expect(Banner::where('position', BannerPosition::Main->value)->whereNotNull('cinema_id')->exists())->toBeFalse();
});

test('every banner has a required alt text and image path', function () {
    Artisan::call('db:seed', ['--class' => BannerSeeder::class, '--force' => true]);

    expect(Banner::whereNull('alt')->orWhere('alt', '')->exists())->toBeFalse();
    expect(Banner::whereNull('image_path')->orWhere('image_path', '')->exists())->toBeFalse();
});

test('non-main banners are distributed between all-cinema and a specific cinema', function () {
    Artisan::call('db:seed', ['--class' => BannerSeeder::class, '--force' => true]);

    $nonMain = Banner::where('position', '!=', BannerPosition::Main->value);

    expect((clone $nonMain)->whereNull('cinema_id')->exists())->toBeTrue();
    expect((clone $nonMain)->whereNotNull('cinema_id')->exists())->toBeTrue();
});

test('positions with more than two banners have exactly one expired demo banner', function () {
    Artisan::call('db:seed', ['--class' => BannerSeeder::class, '--force' => true]);

    foreach (SeedConfig::BANNER_COUNTS as $position => $count) {
        $expiredCount = Banner::where('position', $position)->where('ends_at', '<', now())->count();

        expect($expiredCount)->toBe($count > 2 ? 1 : 0);
    }
});

test('every position has at least one currently active cinema-specific banner', function () {
    Artisan::call('db:seed', ['--class' => BannerSeeder::class, '--force' => true]);

    foreach (SeedConfig::BANNER_COUNTS as $position => $count) {
        if ($count < 2) {
            continue;
        }

        $activeCinemaSpecificCount = Banner::where('position', $position)
            ->whereNotNull('cinema_id')
            ->where(function ($query) {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->count();

        expect($activeCinemaSpecificCount)->toBeGreaterThanOrEqual(1);
    }
});

test('re-seeding does not duplicate banners', function () {
    Artisan::call('db:seed', ['--class' => BannerSeeder::class, '--force' => true]);
    $firstCount = Banner::count();

    Artisan::call('db:seed', ['--class' => BannerSeeder::class, '--force' => true]);

    expect(Banner::count())->toBe($firstCount);
});
