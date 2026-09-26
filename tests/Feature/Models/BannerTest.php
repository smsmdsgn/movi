<?php

use App\Models\Banner;
use Carbon\CarbonImmutable;

/**
 * `Banner::visibleAt()`（顧客側のSQL判定）と `isVisibleAt()`（管理画面の行ごとの判定）が
 * 同じ境界で判定することを固定する（4.7.5追記表「掲載期間の境界」。両端を含む）。
 */
it('掲載期間の判定がクエリスコープと isVisibleAt() で一致する', function (?string $startsAt, ?string $endsAt, bool $expected) {
    /* 秒未満を持たせる。SQL 側は秒で切り捨てて比較するため、PHP 側も同じ扱いであることを固定する。 */
    $now = CarbonImmutable::parse('2026-10-01 12:00:00.500000');

    $banner = createBanner([
        'starts_at' => $startsAt === null ? null : CarbonImmutable::parse($startsAt),
        'ends_at' => $endsAt === null ? null : CarbonImmutable::parse($endsAt),
    ]);

    expect($banner->isVisibleAt($now))->toBe($expected)
        ->and(Banner::query()->visibleAt($now)->whereKey($banner->id)->exists())->toBe($expected);
})->with([
    '期間未指定' => [null, null, true],
    '開始日時ちょうど' => ['2026-10-01 12:00:00', null, true],
    '開始日時の1秒前' => ['2026-10-01 12:00:01', null, false],
    '終了日時ちょうど' => [null, '2026-10-01 12:00:00', true],
    '終了日時の1秒後' => [null, '2026-10-01 11:59:59', false],
    '期間内' => ['2026-09-01 00:00:00', '2026-10-31 23:59:59', true],
]);

it('顧客側に出すリンク先は http・https と自サイトのパスに限る', function (?string $linkUrl, ?string $expected) {
    expect(createBanner(['link_url' => $linkUrl])->safeLinkUrl())->toBe($expected);
})->with([
    'なし' => [null, null],
    'https' => ['https://example.com/campaign', 'https://example.com/campaign'],
    'http（大文字）' => ['HTTP://example.com', 'HTTP://example.com'],
    '自サイトのパス' => ['/cinemas/gion', '/cinemas/gion'],
    'javascript' => ['javascript:alert(1)', null],
    'data' => ['data:text/html,<script>alert(1)</script>', null],
    'プロトコル相対' => ['//evil.example.com', null],
    'バックスラッシュ' => ['/\\evil.example.com', null],
    '相対パス' => ['cinemas/gion', null],
    '空白を含む' => ['https://example.com/ onmouseover=alert(1)', null],
]);
