<?php

use App\Enums\PostStatus;
use App\Models\Admin;
use App\Models\Post;
use App\Models\PostCategory;
use Database\Seeders\MasterDataSeeder;
use Database\Seeders\PostSeeder;
use Database\Seeders\SeedConfig;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    Artisan::call('db:seed', ['--class' => MasterDataSeeder::class, '--force' => true]);
    createTheater();
    createTheater();
    createTheater();
});

test('seeding creates the configured number of posts per category', function () {
    Artisan::call('db:seed', ['--class' => PostSeeder::class, '--force' => true]);

    expect(Post::count())->toBe(count(SeedConfig::POST_CATEGORIES) * SeedConfig::POST_COUNT_PER_CATEGORY);

    foreach (array_keys(SeedConfig::POST_CATEGORIES) as $slug) {
        $category = PostCategory::where('slug', $slug)->firstOrFail();
        expect(Post::where('category_id', $category->id)->count())->toBe(SeedConfig::POST_COUNT_PER_CATEGORY);
    }
});

test('a portion of each category is draft with no published_at, to verify hidden behavior', function () {
    Artisan::call('db:seed', ['--class' => PostSeeder::class, '--force' => true]);

    foreach (array_keys(SeedConfig::POST_CATEGORIES) as $slug) {
        $category = PostCategory::where('slug', $slug)->firstOrFail();

        $draftPosts = Post::where('category_id', $category->id)->where('status', PostStatus::Draft->value)->get();

        expect($draftPosts)->toHaveCount(SeedConfig::POST_DRAFT_COUNT_PER_CATEGORY);
        expect($draftPosts->every(fn (Post $post) => $post->published_at === null))->toBeTrue();
    }
});

test('a portion of each category is published with a future published_at, to verify hidden behavior', function () {
    Artisan::call('db:seed', ['--class' => PostSeeder::class, '--force' => true]);

    foreach (array_keys(SeedConfig::POST_CATEGORIES) as $slug) {
        $category = PostCategory::where('slug', $slug)->firstOrFail();

        $futurePosts = Post::where('category_id', $category->id)
            ->where('status', PostStatus::Published->value)
            ->where('published_at', '>', now())
            ->get();

        expect($futurePosts)->toHaveCount(SeedConfig::POST_FUTURE_COUNT_PER_CATEGORY);
    }
});

test('the remaining posts are published with published_at spread across the past years', function () {
    Artisan::call('db:seed', ['--class' => PostSeeder::class, '--force' => true]);

    $normalCount = SeedConfig::POST_COUNT_PER_CATEGORY
        - SeedConfig::POST_DRAFT_COUNT_PER_CATEGORY
        - SeedConfig::POST_FUTURE_COUNT_PER_CATEGORY;

    foreach (array_keys(SeedConfig::POST_CATEGORIES) as $slug) {
        $category = PostCategory::where('slug', $slug)->firstOrFail();

        $pastPosts = Post::where('category_id', $category->id)
            ->where('status', PostStatus::Published->value)
            ->where('published_at', '<=', now())
            ->get();

        expect($pastPosts)->toHaveCount($normalCount);

        $oldest = $pastPosts->min('published_at');
        $expectedSpanDays = SeedConfig::POST_PUBLISHED_SPAN_YEARS * 365;
        expect((int) $oldest->diffInDays(now()))->toBeGreaterThanOrEqual($expectedSpanDays - 2);
    }
});

test('posts are attributed to the seeded super-admin', function () {
    Artisan::call('db:seed', ['--class' => PostSeeder::class, '--force' => true]);

    $adminId = Admin::where('login_id', SeedConfig::SUPER_ADMIN_LOGIN_ID)->firstOrFail()->id;

    expect(Post::where('created_by_admin_id', $adminId)->count())->toBe(Post::count());
});

test('posts are distributed between all-cinema and a specific cinema', function () {
    Artisan::call('db:seed', ['--class' => PostSeeder::class, '--force' => true]);

    expect(Post::whereNull('cinema_id')->exists())->toBeTrue();
    expect(Post::whereNotNull('cinema_id')->exists())->toBeTrue();
});

test('re-seeding does not duplicate posts', function () {
    Artisan::call('db:seed', ['--class' => PostSeeder::class, '--force' => true]);
    $firstCount = Post::count();

    Artisan::call('db:seed', ['--class' => PostSeeder::class, '--force' => true]);

    expect(Post::count())->toBe($firstCount);
});
